<?php

namespace Rhino\DataTable\Solr;

use Rhino\DataTable\DataTable;
use Rhino\DataTable\InputData;
use Solarium\Core\Query\Helper;

class SolrDataTable extends DataTable
{
    const SOLR_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    private $solarium;
    private $filterQueries = [];

    public function __construct(\Solarium\Client $solarium)
    {
        $this->solarium = $solarium;
    }

    public function processSource(InputData $input)
    {
        $columns = $this->getColumns();

        /** @var \Solarium\QueryType\Select\Query\Query */
        $query = $this->solarium->createQuery($this->solarium::QUERY_SELECT);

        $fields = [];
        foreach ($columns as $column) {
            $fields[] = $column->getName();
        }
        $fields = implode(',', $fields);
        $query->setFields($fields);

        if ($this->getSearch()) {
            $complex = false;
            $filters = [];
            foreach ($columns as $column) {
                if ($column->isSearchable()) {
                    [$searchValue, $columnComplex] = $this->formatSearchValue($column, $this->getSearch(), $query->getHelper());
                    if ($columnComplex) {
                        $complex = true;
                    }
                    $preset = $column->getPreset();
                    $preset = $preset['preset'] ?? 'none';
                    switch ($preset) {
                        case 'none':
                        case 'array':
                        case 'trim':
                        case 'trimHtml':
                            $filters[] = $searchValue;
                            break;

                        case 'id':
                        case 'number':
                        case 'percent':
                        case 'money':
                            if (is_numeric($this->getSearch())) {
                                $filters[] = $searchValue;
                            }
                            break;

                        case 'bool':
                            break;

                        case 'date':
                        case 'dateTime':
                            break;
                    }
                }
            }
            $filters = implode(' || ', $filters);
            if ($complex) {
                $filters = '{!complexphrase inOrder=false}' . $filters;
            }
            $query->setQuery($filters);
        }

        // @todo filtered totals?
        // @todo default copy field
        // @todo bool drop downs

        foreach ($this->getInputColumns() as $i => $inputColumn) {
            if (isset($inputColumn['search']['value']) && $inputColumn['search']['value'] && isset($columns[$i])) {
                $searchValue = $inputColumn['search']['value'];
                $column = $columns[$i];
                if ($columns[$i]->hasFilterSelect()) {
                    // Select menu filters
                    $filterSelect = $columns[$i]->getFilterSelect($searchValue);
                    if ($filterSelect) {
                        $query->createFilterQuery($columns[$i]->getName())->setQuery($filterSelect['query']);
                    }
                } elseif ($column->hasFilterDateRange()) {
                    // Date range filters
                    $from = null;
                    $to = null;
                    $filterDateRange = $columns[$i]->getFilterDateRange();
                    $timeZone = $filterDateRange['timeZone'];

                    if (preg_match('/(?<from>[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}) to (?<to>[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2})/', $searchValue, $matches)) {
                        // d($matches);
                        $from = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $matches['from'], $timeZone);
                        $to = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $matches['to'], $timeZone);
                    } elseif (preg_match('/(?<from>[0-9]{4}-[0-9]{2}-[0-9]{2})/', $searchValue, $matches)) {
                        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $matches['from'], $timeZone);
                        $from = $from->setTime(0, 0, 0);
                        $to = $from->setTime(23, 59, 59);
                    }

                    if ($from && $to) {
                        if ($from > $to) {
                            $temp = $to;
                            $to = $from;
                            $from = $temp;
                        }
                        $from = $from->setTime($from->format('H'), $from->format('i'), 0)->setTimezone(new \DateTimeZone('UTC'))->format(static::SOLR_DATE_FORMAT);
                        $to = $to->setTime($to->format('H'), $to->format('i'), 59)->setTimezone(new \DateTimeZone('UTC'))->format(static::SOLR_DATE_FORMAT);

                        $query->createFilterQuery($column->getName())->setQuery($column->getName() . ':[' . $from . ' TO ' . $to . ']');
                    } else {
                        // Fallback if input was typed in
                        $this->filterText($column, $searchValue, $query);
                    }
                } else {
                    // Text input filters
                    $this->filterText($column, $searchValue, $query);
                }
            }
        }

        foreach ($this->filterQueries as $i => $filterQuery) {
            $query->createFilterQuery('filter_query_' . $i)->setQuery($filterQuery['filterQuery'], $filterQuery['bindings']);
        }

        foreach ($this->order as $columnIndex => $direction) {
            if ($columns[$columnIndex]->isSortable()) {
                $query->addSort($columns[$columnIndex]->getName(), $direction);
            }
        }

        $query->setStart($this->getStart());
        $query->setRows($this->getLength());

        /** @var \Solarium\QueryType\Select\Result\Document[] */
        // d($query->getDebug());
        $results = $this->solarium->execute($query);

        $total = $results->getNumFound();

        $data = [];
        foreach ($results as $document) {
            $row = [];
            foreach ($columns as $column) {
                $row[] = $document[$column->getName()];
            }
            $data[] = $row;
        }

        $this->setData($data);
        $this->setRecordsTotal($total);
        $this->setRecordsFiltered($total);
    }

    private function filterText(SolrColumn $column, string $searchValue, \Solarium\QueryType\Select\Query\Query $query)
    {
        [$searchValue, $complex] = $this->formatSearchValue($column, $searchValue, $query->getHelper());
        if ($complex) {
            $searchValue = '{!complexphrase inOrder=false}' . $searchValue;
        }
        // d($searchValue);
        $query->createFilterQuery($column->getName())->setQuery($searchValue);
    }

    private function formatSearchValue(SolrColumn $column, string $searchValue, Helper $helper)
    {
        $searchValue = trim($searchValue);
        if (preg_match('/^[a-z0-9]+~[0-9]*$/i', $searchValue)) {
            // Fuzzy search
            $searchValue = $column->getName() . ':' . $searchValue;
            return [$searchValue, false];
        } elseif (preg_match('/^[0-9.]+$/', $searchValue)) {
            // Exact number search
            $searchValue = $column->getName() . ':' . $searchValue;
            return [$searchValue, false];
        } elseif (preg_match('/^[0-9*.]+\s*TO\s*[0-9*.]+$/i', $searchValue)) {
            // Range search
            $searchValue = '[' . strtoupper($searchValue) . ']';
            $searchValue = $column->getName() . ':' . $searchValue;
            return [$searchValue, false];
        }

        $searchValues = [];

        // Term
        $searchValues[] = $column->getName() . ':' . '*' . $helper->escapeTerm($searchValue) . '*';

        // Phrase
        $searchValues[] = $column->getName() . ':' . preg_replace('/\s+\*+\s+/', ' ', $helper->escapePhrase($searchValue));
        $searchValues[] = $column->getName() . ':' . preg_replace('/\s+\*+\s+/', ' ', $helper->escapePhrase('*' . $searchValue . '*'));

        $searchValue = implode(' || ', $searchValues);
// d($searchValue);
        return [$searchValue, true];
    }

    public function addColumn($name, $index = null)
    {
        $column = new SolrColumn($name, $this);
        if ($index !== null) {
            array_splice($this->columns, $index, 0, $column);
        } else {
            $this->columns[] = $column;
        }
        return $column;
    }

    public function insertColumn($name, $format, $position = null)
    {
        return $this->spliceColumn(new SolrColumnInsert($this, $name, $format), $position);
    }

    public function addFilterQuery(string $filterQuery, array $bindings = [])
    {
        $this->filterQueries[] = [
            'filterQuery' => $filterQuery,
            'bindings' => $bindings,
        ];
    }
}
