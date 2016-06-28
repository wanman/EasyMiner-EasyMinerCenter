<?php

namespace EasyMinerCenter\Model\Preprocessing\Entities;

/**
 * Class PpValue - třída představující jednu hodnotu předzpracovaného atributu
 * @package EasyMinerCenter\Model\Preprocessing\Entities
 * @author Stanislav Vojíř
 *
 * @property int $id
 * @property int $frequency
 * @property string $value
 */
class PpValue{

  public $id;
  public $frequency;
  public $value;

  /**
   * @param $id
   * @param $value
   * @param $frequency
   */
  public function __construct($id, $value, $frequency){
    $this->id=$id;
    $this->frequency=$frequency;
    $this->value=$value;
  }

}