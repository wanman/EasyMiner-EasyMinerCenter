<?php

namespace EasyMinerCenter\EasyMinerModule\Presenters;

use EasyMinerCenter\Model\EasyMiner\Entities\RuleSet;
use EasyMinerCenter\Model\EasyMiner\Entities\RuleSetRuleRelation;
use EasyMinerCenter\Model\EasyMiner\Facades\RulesFacade;
use EasyMinerCenter\Model\EasyMiner\Facades\RuleSetsFacade;
use EasyMinerCenter\Model\EasyMiner\Facades\UsersFacade;
use EasyMinerCenter\Model\EasyMiner\Serializers\AssociationRulesXmlSerializer;
use EasyMinerCenter\Model\EasyMiner\Transformators\XmlTransformator;
use Nette\InvalidArgumentException;

/**
 * Class RuleSetsPresenter - presenter pro práci s rulesety
 * @package EasyMinerCenter\KnowledgeBaseModule\Presenters
 * @author Stanislav Vojíř
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */
class RuleSetsPresenter extends BasePresenter{
  use ResponsesTrait;

  /** @var  RulesFacade $rulesFacade */
  private $rulesFacade;
  /** @var  RuleSetsFacade $ruleSetsFacade */
  private $ruleSetsFacade;
  /** @var  UsersFacade $usersFacade */
  private $usersFacade;
  /** @var  XmlTransformator $xmlTransformator */
  private $xmlTransformator;


  /**
   * Action for serialization of Rules in the RuleSet in DRL form
   * @param int $id - id of the RuleSet
   * @throws \Exception
   * @throws \Nette\Application\ForbiddenRequestException
   */
  public function renderDRL($id){
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    //generate DRL and send it as text response
    $associationRulesXmlSerializer=new AssociationRulesXmlSerializer($ruleSet->findRules());
    $xml=$associationRulesXmlSerializer->getXml();
    $this->sendTextResponse($this->xmlTransformator->transformToDrl($xml));
  }

  /**
   * Action for list of existing RuleSets
   */
  public function actionList(){
    $ruleSets=$this->ruleSetsFacade->findRuleSetsByUser($this->user->id);
    $result=[];
    if (empty($ruleSets)) {
      //if no RuleSet found, create a new one...
      $ruleSet=new RuleSet();
      $user=$this->usersFacade->findUser($this->user->id);
      $ruleSet->user=$user;
      $ruleSet->name=$user->name;
      $this->ruleSetsFacade->saveRuleSet($ruleSet);
      $result[$ruleSet->ruleSetId]=$ruleSet->getDataArr();
    }else{
      foreach ($ruleSets as $ruleSet){
        $result[$ruleSet->ruleSetId]=$ruleSet->getDataArr();
      }
    }
    $this->sendJsonResponse($result);
  }

  #region actions for manipulation with a RuleSet

  /**
   * Action for creation of a new RuleSet (with the given name)
   * @param string $name
   * @param string $description=""
   * @throws InvalidArgumentException
   */
  public function actionNew($name, $description=""){
    $this->ruleSetsFacade->checkUniqueRuleSetNameByUser($name,$this->user->id);
    //create new RuleSet
    $ruleSet=new RuleSet();
    $ruleSet->name=$name;
    $ruleSet->description=$description;
    $ruleSet->user=$this->usersFacade->findUser($this->user->id);
    $this->ruleSetsFacade->saveRuleSet($ruleSet);
    //send the response
    $this->sendJsonResponse($ruleSet->getDataArr());
  }

  /**
   * Action for renaming of an existing RuleSet (description change is also supported)
   * @param int $id
   * @param string $name
   * @param string $description=""
   */
  public function actionRename($id,$name,$description=""){
    //find the RuleSet and check it
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    $this->ruleSetsFacade->checkUniqueRuleSetNameByUser($name,$this->user->id,$ruleSet);
    //change the name and description and save it
    $ruleSet->name=$name;
    $ruleSet->description=$description;
    $this->ruleSetsFacade->saveRuleSet($ruleSet);
    //send the response
    $this->sendJsonResponse($ruleSet->getDataArr());
  }

  /**
   * Action for deleting of a RuleSet
   * @param int $id
   */
  public function actionDelete($id){
    //find RuleSet and check it
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    //delete the RuleSet and send confirmation response
    if ($this->ruleSetsFacade->deleteRuleSet($ruleSet)){
      $this->sendJsonResponse(['state'=>'ok']);
    }
  }

  #endregion actions for manipulation with a RuleSet

  #region actions for manipulation of relations between Rules and RuleSets

  /**
   * Action returning one concrete RuleSet with basic list of Rules
   * @param int $id
   * @param int $offset
   * @param int $limit
   * @param string|null $order = null
   */
  public function actionGetRules($id,$offset=0,$limit=25,$order=null){
    //find RuleSet and check it
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    //prepare the result
    $result=[
      'ruleset'=>$ruleSet->getDataArr(),
      'rules'=>[]
    ];
    if ($ruleSet->rulesCount>0 || true){
      $rules=$this->ruleSetsFacade->findRulesByRuleSet($ruleSet,$order,$offset,$limit);
      if (!empty($rules)){
        foreach($rules as $rule){
          $result['rules'][$rule->ruleId]=$rule->getBasicDataArr();
        }
      }
    }
    $this->sendJsonResponse($result);
  }

  /**
   * Action for adding of Rules to a RuleSet
   * @param int $id
   * @param string|int $rules - ID of Rules, separated with commas or semicolons
   * @param string $relation = 'positive'
   * @param string $result = "simple" (varianty "simple", "rules")
   */
  public function actionAddRules($id,$rules,$relation=RuleSetRuleRelation::RELATION_POSITIVE, $result="simple"){
    //fing RuleSet and check it
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    /** @var int[] $ruleIdsArr */
    $ruleIdsArr=explode(',',str_replace(';',',',$rules));
    if (!empty($ruleIdsArr)){
      foreach($ruleIdsArr as $ruleId){
        if (!$ruleId){continue;}
        try{
          $rule=$this->rulesFacade->findRule($ruleId);
          $this->ruleSetsFacade->addRuleToRuleSet($rule,$ruleSet,$relation);
        }catch (\Exception $e){continue;}
      }
    }
    if ($result=="rules"){
      $result=[
        'ruleset'=>$ruleSet->getDataArr(),
        'rules'=>[]
      ];
      $result['rules']=$this->prepareRulesResult($ruleIdsArr,$ruleSet);
      $this->sendJsonResponse($result);
    }else{
      $this->sendJsonResponse(['state'=>'ok']);
    }
  }

  /**
   * Action for removing of Rules from a RuleSet
   * @param int $id
   * @param string|int $rules
   * @param string $result = "simple" (varianty "simple", "rules")
   */
  public function actionRemoveRules($id, $rules, $result="simple"){
    //find RuleSet and check it
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    /** @var int[] $ruleIdsArr */
    $ruleIdsArr=explode(',',str_replace(';',',',$rules));
    if (!empty($ruleIdsArr)){
      foreach($ruleIdsArr as $ruleId){
        if (!$ruleId){continue;}
        try{
          $rule=$this->rulesFacade->findRule($ruleId);
          $this->ruleSetsFacade->removeRuleFromRuleSet($rule,$ruleSet);
        }catch (\Exception $e){continue;}
      }
    }
    $this->ruleSetsFacade->updateRuleSetRulesCount($ruleSet);

    if ($result=="rules"){
      $result=[
        'ruleset'=>$ruleSet->getDataArr(),
        'rules'=>[]
      ];
      $result['rules']=$this->prepareRulesResult($ruleIdsArr, $ruleSet);
      $this->sendJsonResponse($result);
    }else{
      $this->sendJsonResponse(['state'=>'ok']);
    }
  }

  /**
   * Action for removing of all Rules from the given RuleSet
   * @param int $id
   */
  public function actionRemoveAllRules($id){
    //find RuleSet and check it
    $ruleSet=$this->ruleSetsFacade->findRuleSet($id);
    $this->ruleSetsFacade->checkRuleSetAccess($ruleSet,$this->user->id);
    //remove the relations
    $this->ruleSetsFacade->removeAllRulesFromRuleSet($ruleSet);
    $this->sendJsonResponse(['state'=>'ok']);
  }

  /**
   * Method for preparation of an array with information about selected Rules for sending in a JSON response
   * @param int[] $ruleIdsArr
   * @param RuleSet|int $ruleSet
   * @return array
   */
  private function prepareRulesResult($ruleIdsArr, $ruleSet=null){
    $result=[];
    if (!empty($ruleIdsArr)) {
      foreach ($ruleIdsArr as $ruleId) {
        if (!$ruleId) {
          continue;
        }
        try {
          $rule = $this->rulesFacade->findRule($ruleId);
          $result[$rule->ruleId]=$rule->getBasicDataArr();
          if (!empty($ruleSet)){
            $relation=$rule->getRuleSetRelation($ruleSet);
            if (!empty($relation)){
              $result[$rule->ruleId]['ruleSetRelation']=$relation->relation;
            }else{
              $result[$rule->ruleId]['ruleSetRelation']='';
            }
          }
        }catch (\Exception $e){continue;}
      }
    }
    return $result;
  }

  #endregion actions for manipulation of relations between Rules and RuleSets

  #region injections
  /**
   * @param RulesFacade $rulesFacade
   */
  public function injectRulesFacade(RulesFacade $rulesFacade){
    $this->rulesFacade=$rulesFacade;
  }
  /**
   * @param RuleSetsFacade $ruleSetsFacade
   */
  public function injectRuleSetsFacade(RuleSetsFacade $ruleSetsFacade){
    $this->ruleSetsFacade=$ruleSetsFacade;
  }
  /**
   * @param UsersFacade $usersFacade
   */
  public function injectUsersFacade(UsersFacade $usersFacade){
    $this->usersFacade=$usersFacade;
  }
  /**
   * @param XmlTransformator $xmlTransformator
   */
  public function injectXmlTransformator(XmlTransformator $xmlTransformator){
    $this->xmlTransformator=$xmlTransformator;
    //nastaven basePath
    $this->xmlTransformator->setBasePath($this->template->basePath);
  }
  #endregion injections
}