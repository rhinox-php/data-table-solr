<?php

namespace Rhino\DataTable\Solr;

use Rhino\DataTable\Column;

class SolrColumn extends Column
{
    public function setPreset($preset, $options = null): Column
    {
        switch ($preset) {
            case 'bool':
                $this->setFilterSelect([
                    'Yes' => [
                        'query' => $this->getName() . ':1',
                    ],
                    'No' => [
                        'query' => $this->getName() . ':0',
                    ],
                ]);
                break;

            case 'array':
                $this->setSortable(false);
                break;
        }
        return parent::setPreset($preset, $options);
    }
}
