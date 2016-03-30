<?php

namespace EasyMinerCenter\Model\EasyMiner\Facades;
use EasyMinerCenter\Model\EasyMiner\Entities\Attribute;
use EasyMinerCenter\Model\EasyMiner\Entities\Metasource;
use EasyMinerCenter\Model\EasyMiner\Entities\MetasourceTask;
use EasyMinerCenter\Model\EasyMiner\Entities\Miner;
use EasyMinerCenter\Model\EasyMiner\Entities\User;
use EasyMinerCenter\Model\EasyMiner\Repositories\AttributesRepository;
use EasyMinerCenter\Model\EasyMiner\Repositories\MetasourcesRepository;
use EasyMinerCenter\Model\EasyMiner\Repositories\MetasourceTasksRepository;
use EasyMinerCenter\Model\Preprocessing\Databases\IPreprocessing;
use EasyMinerCenter\Model\Preprocessing\Databases\PreprocessingFactory;
use EasyMinerCenter\Model\Preprocessing\Entities\PpConnection;
use EasyMinerCenter\Model\Preprocessing\Entities\PpDataset;
use EasyMinerCenter\Model\Preprocessing\Entities\PpTask;
use EasyMinerCenter\Model\Preprocessing\Exceptions\PreprocessingCommunicationException;
use Nette\NotImplementedException;

/**
 * Class MetasourcesFacade - fasáda pro práci s jednotlivými metasources (datasety)
 *
 * @package EasyMinerCenter\Model\EasyMiner\Facades
 * @author Stanislav Vojíř
 */
class MetasourcesFacade {
  /** @var PreprocessingFactory $preprocessingFactory */
  private $preprocessingFactory;
  /** @var AttributesRepository $attributesRepository */
  private $attributesRepository;
  /** @var MetasourcesRepository $metasourcesRepository*/
  private $metasourcesRepository;
  /** @var MetasourceTasksRepository $metasourceTasksRepository */
  private $metasourceTasksRepository;
  /** @var DatasourcesFacade $datasourcesFacade */
  private $datasourcesFacade;

  /**
   * Funkce pro export pole s informacemi z TransformationDictionary
   * @param Metasource $metasource
   * @param User $user
   * @param int &rowsCount = null - počet řádků v datasource
   * @return array
   */
  public function exportTransformationDictionaryArr(Metasource $metasource, User $user, &$rowsCount = null) {
    $output = [];
    $this->updateMetasourceAttributes($metasource, $user);//aktualizace seznamu datových sloupců

    foreach($metasource->attributes as $attribute){
      if (!$attribute->active){continue;}
      $output[$attribute->attributeId]=[
        'name'=>$attribute->name,
        'type'=>$attribute->type,
        'choices'=>[]
      ];
    }
    $rowsCount = $metasource->size;
    return $output;
  }

  /**
   * Funkce pro nalezení metasource dle zadaného ID
   * @param int $id
   * @return Metasource
   */
  public function findMetasource($id) {
    return $this->metasourcesRepository->find($id);
  }

  /**
   * Funkce pro nalezení MetasourceTask podle ID
   * @param int $id
   * @return MetasourceTask
   * @throws \EasyMinerCenter\Exceptions\EntityNotFoundException
   */
  public function findMetasourceTask($id) {
    return $this->metasourceTasksRepository->find($id);
  }

  /**
   * Funkce pro uložení MetasourceTask
   * @param MetasourceTask $metasourceTask
   */
  public function saveMetasourceTask(MetasourceTask $metasourceTask) {
    $this->metasourceTasksRepository->persist($metasourceTask);
  }

  /**
   * @param Metasource $metasource
   */
  public function saveMetasource(Metasource $metasource) {
    $this->metasourcesRepository->persist($metasource);
  }

  /**
   * @param MetasourceTask $metasourceTask
   * @return int
   * @throws \LeanMapper\Exception\InvalidStateException
   */
  public function deleteMetasourceTask(MetasourceTask $metasourceTask) {
    return $this->metasourceTasksRepository->delete($metasourceTask);
  }

  /**
   * Funkce vracející přehled typů preprocessingů, které jsou podporované daným ovladačem preprocessingu
   * @param Metasource $metasource
   * @param User $user
   * @return string[]
   */
  public function getSupportedPreprocessingTypes(Metasource $metasource, User $user) {
    /** @var IPreprocessing $preprocessing */
    $preprocessing=$this->preprocessingFactory->getPreprocessingInstance($metasource->getPpConnection(), $user);
    return $preprocessing->getSupportedPreprocessingTypes();
  }

  /**
   * Funkce pro aktualizaci info o atributech v DB
   * @param Metasource &$metasource
   * @param User $user
   */
  public function updateMetasourceAttributes(Metasource &$metasource, User $user) {
    /** @var IPreprocessing $preprocessing */
    $preprocessing=$this->preprocessingFactory->getPreprocessingInstance($metasource->getPpConnection(), $user);

    $ppDataset=$preprocessing->getPpDataset($metasource->ppDatasetId?$metasource->ppDatasetId:$metasource->name);
    $datasource=$metasource->datasource;
    $metasource->size=$ppDataset->size;
    $ppAttributes=$preprocessing->getPpAttributes($ppDataset);

    #region připravení seznamu aktuálně existujících datasourceColumns
    /** @var Attribute[] $existingAttributesByPpDatasetAttributeId */
    $existingAttributesByPpDatasetAttributeId=[];
    /** @var Attribute[] $existingAttributesByName */
    $existingAttributesByName=[];
    /** @var Attribute[] $attributes */
    $attributes=$metasource->attributes;
    if (!empty($attributes)){
      foreach ($attributes as &$attribute){
        if (!empty($attribute->ppDatasetAttributeId)){
          $existingAttributesByPpDatasetAttributeId[$attribute->ppDatasetAttributeId]=$attribute;
        }else{
          $existingAttributesByName[$attribute->name]=$attribute;
        }

      }
    }
    #endregion

    #region aktualizace seznamu sloupců získaných z DB
    if (!empty($ppAttributes)){
      foreach($ppAttributes as $ppAttribute){
        if (!empty($ppAttribute->id) && is_int($ppAttribute->id) && isset($existingAttributesByPpDatasetAttributeId[$ppAttribute->id])){
          //sloupec s daným ID již je v databázi
          $attribute=$existingAttributesByPpDatasetAttributeId[$ppAttribute->id];
          $modified=false;
          if ($attribute->name!=$ppAttribute->name){
            $attribute->name=$ppAttribute->name;
            $modified=true;
          }
          if (!$attribute->active){
            $modified=true;
            $attribute->active=true;
          }
          if ($modified){
            $this->saveAttribute($attribute);
          }
          unset($existingAttributesByPpDatasetAttributeId[$ppAttribute->id]);
        }elseif(!empty($ppAttribute->name) && isset($existingAttributesByName[$ppAttribute->name])){
          //sloupec najdeme podle jména
          $attribute=$existingAttributesByName[$ppAttribute->name];
          if (!$attribute->active){
            $attribute->active=true;
            $this->saveAttribute($attribute);
          }
          unset($existingAttributesByName[$ppAttribute->name]);
        }elseif (!empty($ppAttribute->field)){
          //máme tu nový datový sloupec (který má svoji vazbu na datasource)
          $attribute=new Attribute();
          $attribute->metasource=$metasource;
          try{
            $datasourceColumn=$this->datasourcesFacade->findDatasourceColumnByDbDatasourceColumnId($datasource,$ppAttribute->field);
          }catch (\Exception $e){
            $datasourceColumn=null;
          }
          $attribute->datasourceColumn=$datasourceColumn;
          $attribute->name=$ppAttribute->name;
          if (is_int($ppAttribute->id)){
            $attribute->ppDatasetAttributeId=$ppAttribute->id;
          }
          $attribute->active=true;
          $attribute->type=$ppAttribute->type;
          $this->saveAttribute($attribute);
        }
      }
    }
    #endregion
    #region deaktivace již neexistujících sloupců
    if (!empty($existingAttributesByPpDatasetAttributeId)){
      foreach($existingAttributesByPpDatasetAttributeId as &$attribute){
        if ($attribute->active){
          $attribute->active=false;
          $this->saveAttribute($attribute);
        }
      }
    }
    if (!empty($existingAttributesByName)){
      foreach($existingAttributesByName as &$attribute){
        if ($attribute->active){
          $attribute->active=false;
          $this->saveAttribute($attribute);
        }
      }
    }
    #endregion
    //aktualizace datového zdroje z DB
    $metasource=$this->findMetasource($metasource->metasourceId);
  }

  /**
   * @param Attribute &$attribute
   * @return Attribute
   */
  public function saveAttribute(Attribute $attribute) {
    $attributeId=$this->attributesRepository->persist($attribute);
    return $this->attributesRepository->find($attributeId);
  }

  /**
   * @param PreprocessingFactory $preprocessingFactory
   * @param AttributesRepository $attributesRepository
   * @param MetasourcesRepository $metasourcesRepository
   * @param MetasourceTasksRepository $metasourceTasksRepository
   * @param DatasourcesFacade $datasourcesFacade
   */
  public function __construct(PreprocessingFactory $preprocessingFactory, AttributesRepository $attributesRepository, MetasourcesRepository $metasourcesRepository, MetasourceTasksRepository $metasourceTasksRepository, DatasourcesFacade $datasourcesFacade) {
    $this->preprocessingFactory=$preprocessingFactory;
    $this->attributesRepository=$attributesRepository;
    $this->metasourcesRepository=$metasourcesRepository;
    $this->metasourceTasksRepository=$metasourceTasksRepository;
    $this->datasourcesFacade=$datasourcesFacade;
  }

  #region inicializace metasource

  /**
   * Funkce pro inicializaci Metasource pro konkrétní miner
   *
   * @param Miner $miner
   * @param MinersFacade $minersFacade
   * @return MetasourceTask
   */
  public function startMinerMetasourceInitialization(Miner $miner, MinersFacade $minersFacade) {
    //vytvoření úlohy pro
    $ppType = $this->preprocessingFactory->getPreprocessingTypeByDatabaseType($miner->datasource->type);
    /** @var PpConnection $ppConnection - DB/API connection for preprocessing */
    $ppConnection = $this->preprocessingFactory->getDefaultPpConnection($ppType, $miner->user);
    $metasource=Metasource::newFromPpConnection($ppConnection);
    $metasource->datasource=$miner->datasource;
    $metasource->state=Metasource::STATE_PREPARATION;
    $metasource->user=$miner->user;
    $this->saveMetasource($metasource);

    //připojení metasource k mineru
    $miner->metasource=$metasource;
    $minersFacade->saveMiner($miner);

    //vytvoření úlohy, v rámci které dojde k inicializaci
    $metasourceTask = new MetasourceTask();
    $metasourceTask->type=MetasourceTask::TYPE_INITIALIZATION;
    $metasourceTask->state=MetasourceTask::STATE_NEW;
    $metasourceTask->metasource=$metasource;
    $this->saveMetasourceTask($metasourceTask);
    return $metasourceTask;
  }

  /**
   * Funkce pro inicializaci metasource pomocí preprocessing driveru
   *
   * @param Miner $miner
   * @param MetasourceTask $metasourceTask
   * @return MetasourceTask
   * @throws \Exception
   */
  public function initializeMinerMetasource(Miner $miner, MetasourceTask $metasourceTask) {
    $metasource=$metasourceTask->metasource;
    if ($miner->metasource->metasourceId!=$metasource->metasourceId){
      throw new \InvalidArgumentException('Different metasource confing in miner and metasource task!');
    }

    $preprocessing=$this->preprocessingFactory->getPreprocessingInstance($metasource->getPpConnection(), $miner->user);
    if ($metasourceTask->state==MetasourceTask::STATE_NEW){
      //inicializace
      $datasource=$metasource->datasource;
      $result=$preprocessing->createPpDataset(new PpDataset(null,$miner->name,$datasource->dbDatasourceId?$datasource->dbDatasourceId:$datasource->dbTable,null,null),null);
    }elseif($metasourceTask->state==MetasourceTask::STATE_IN_PROGRESS){
      //kontrola stavu
      try{
        $result=$preprocessing->createPpDataset(null,$metasourceTask->getPpTask());
      }catch (PreprocessingCommunicationException $e){
        if($e->getCode()==404){
          //pokud nebyla nalezena běžící preprocessing úloha, zkusíme inicializovat novou
          $this->deleteMetasourceTask($metasourceTask);//smažeme stávající MetasourceTask
          //odešleme požadavek na vytvoření nového datasetu
          $datasource=$metasource->datasource;
          $result=$preprocessing->createPpDataset(new PpDataset(null,$miner->name,$datasource->dbDatasourceId?$datasource->dbDatasourceId:$datasource->dbTable,null,null),null);
        }else{
          throw $e;
        }
      }
    }else{
      return $metasourceTask;
    }

    if ($result instanceof PpTask){
      //byla vytvořena dlouhotrvající úloha
      $metasourceTask->state=MetasourceTask::STATE_IN_PROGRESS;
      $metasourceTask->setPpTask($result);
      $this->saveMetasourceTask($metasourceTask);
    }elseif($result instanceof PpDataset){
      //byl dovytvořen dataset
      $metasource->state=MetasourceTask::STATE_DONE;
      $metasource->ppDatasetId=$result->id;
      $metasource->size=$result->size;
      $metasource->type=$result->type;
      $this->saveMetasource($metasource);
      $metasourceTask->state=MetasourceTask::STATE_DONE;
      $this->saveMetasourceTask($metasourceTask);
    }else{
      throw new \Exception('Unexpected type of result!');
    }

    return $metasourceTask;
  }


  #endregion inicializace metasource

  /**
   * Funkce pro kontrolu dostupnosti metasource a případnou aktualizaci seznamu atributů
   * @param Metasource $metasource
   * @throws \Exception
   */
  public function checkMetasourceState(Metasource $metasource) {
    $preprocessing=$this->preprocessingFactory->getPreprocessingInstance($metasource->getPpConnection(),$metasource->user);
    $ppDataset = $preprocessing->getPpDataset($metasource->ppDatasetId?$metasource->ppDatasetId:$metasource->getDbTable());
    //TODO kontrola, jestli je ppDataset dostupný!
    $ppAttributes=$preprocessing->getPpAttributes($ppDataset);
    //TODO aktualizace seznamu sloupců

  }

  /**
   * Funkce pro smazání metasource
   * @param Metasource $metasource
   * @return int
   * @throws \LeanMapper\Exception\InvalidStateException
   */
  public function deleteMetasource(Metasource $metasource) {
    $preprocessing=$this->preprocessingFactory->getPreprocessingInstance($metasource->getPpConnection(),$metasource->user);
    $ppDataset = new PpDataset($metasource->ppDatasetId,$metasource->name,null,$metasource->type,$metasource->size);
    $preprocessing->deletePpDataset($ppDataset);
    return $this->metasourcesRepository->delete($metasource);
  }


  #region preprocessing atributů

  /**
   * Funkce pro přípravu úlohy pro preprocessing atributů
   *
   * @param Metasource $metasource
   * @param Attribute[] $attributes
   * @return MetasourceTask
   */
  public function startAttributesPreprocessing(Metasource $metasource,array $attributes) {
    if (empty($attributes)){
      throw new \BadMethodCallException('No attributes found.');
    }

    //vytvoření úlohy, v rámci které dojde k preprocessingu
    $metasourceTask = new MetasourceTask();
    $metasourceTask->type=MetasourceTask::TYPE_PREPROCESSING;
    $metasourceTask->state=MetasourceTask::STATE_NEW;
    $metasourceTask->metasource=$metasource;
    foreach($attributes as $attribute){
      if ($attribute instanceof Attribute){
        $metasourceTask->attributes[]=$attribute;
      }else{
        throw new \BadMethodCallException('Requested attribute not found!');
      }
    }
    $this->saveMetasourceTask($metasourceTask);
    return $metasourceTask;
  }

  /**
   * Funkce pro preprocessing atributů pomocí preprocessing driveru
   *
   * @param MetasourceTask $metasourceTask
   * @return MetasourceTask
   * @throws \Exception
   */
  public function preprocessAttributes(MetasourceTask $metasourceTask) {
    $metasource=$metasourceTask->metasource;
    $preprocessing=$this->preprocessingFactory->getPreprocessingInstance($metasource->ppConnection, $metasource->user);

    if ($metasourceTask->state==MetasourceTask::STATE_NEW){
      //spuštění nového preprocessingu
      $result=$preprocessing->createAttributes($metasourceTask->attributes,null);
    }elseif($metasourceTask->state==MetasourceTask::STATE_IN_PROGRESS){
      //zjištění stavu probíhajícího preprocessingu
      try{
        $result=$preprocessing->createAttributes(null,$metasourceTask->getPpTask());
      }catch (PreprocessingCommunicationException $e){
        if($e->getCode()==404){
          //pokud úloha nebyla nalezena, označíme ji jako dokončenou...
          $metasourceTask->state=MetasourceTask::STATE_DONE;
          return $metasourceTask;
        }else{
          throw $e;
        }
      }
    }else{
      return $metasourceTask;
    }

    if ($result instanceof PpTask){
      $metasourceTask->state=MetasourceTask::STATE_IN_PROGRESS;
      $metasourceTask->setPpTask($result);
      $this->saveMetasourceTask($metasourceTask);
      return $metasourceTask;
    }elseif(is_array($result)){
      //TODO zpracování odpovědi - pole s atributy...
      exit(var_dump($result));


    }else{
      throw new \Exception('Unexpected type of result!');
    }
  }


  #endregion inicializace metasource



  /**
   * @param Metasource $metasource
   * @param Attribute|int $attribute
   */
  public function prepareAttribute(Metasource $metasource, Attribute $attribute){
    //FIXME implementovat...
    if ($attribute instanceof Attribute){
      if ($attribute->isDetached() || $attribute->isModified()){
        $this->metasourcesFacade->saveAttribute($attribute);
      }
    }else{
      $attribute=$this->metasourcesFacade->findAttribute($attribute);
    }
    $this->preprocessingDriver->generateAttribute($attribute);
    //TODO nechat mining driver zkontrolovat existenci všech atributů
  }


}