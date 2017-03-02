<?php

namespace EasyMinerCenter\Model\EasyMiner\Repositories;

use LeanMapper\Connection;

class RuleRuleRelationsRepository{

    const
        TABLE_NAME = 'rule_rule_relations',
        COLUMN_RULE_SET = 'rule_set_id',
        COLUMN_RULE = 'rule_id',
        COLUMN_RULESET_RULE = 'rule_set_rule_id',
        COLUMN_RELATION = 'relation',
        COLUMN_RATE = 'rate';

    /** @var  Connection $connection */
    private $connection;

    public function __construct(Connection $connection){
        $this->connection=$connection;
    }

    /**
     * Multiinsert of comparing results
     * @param $data values to be inserted
     */
    public function saveComparing($data){
        array_unshift($data, "INSERT INTO " . self::TABLE_NAME);
        $this->connection->query($data);
    }

}