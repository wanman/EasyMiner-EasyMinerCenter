<?php

namespace App\Model\Data\Files;
use App\Model\Data\Entities\DbColumn;
use Nette\Utils\Strings;

/**
 * Class CsvImport - model pro import CSV souborů
 * @package App\Model\Data\Files
 */
class CsvImport {

  /**
   * Funkce pro změnu kódování souboru (ze zadaného kódování na UTF8)
   * @param string $filename
   * @param string $originalEncoding
   */
  public static function iconvFile($filename,$originalEncoding){
    $originalFilePath=$filename.'.original';
    if (!file_exists($originalFilePath)){
      rename($filename,$originalFilePath);
    }

    if ($originalEncoding=='utf8'){
      copy($originalFilePath,$filename);
      return;
    }

    $file=fopen($originalFilePath,'r');
    $file2=fopen($filename,'w');
    while ($row=fgets($file)){
      $rowNew=iconv($originalEncoding,'utf-8',$row);
      fputs($file2,$rowNew);
    }
    fclose($file2);
    fclose($file);
  }

  /**
   * Funkce vracející zvolený počet řádků z CSV souboru (ignoruje 1. řádek se záhlavím)
   * @param $filename
   * @param int $count = 10000
   * @param string $delimitier = ','
   * @param string $enclosure = '"'
   * @param string $escapeCharacter = '\\'
   * @return array
   */
  public static function getRowsFromCSV($filename,$count=10000,$delimitier=',',$enclosure='"',$escapeCharacter='\\'){
    $file=fopen($filename,'r');
    if ($file===false){return null;}
    $counter=$count;
    $outputArr=array();
    if ($delimitier=='\t'){
      $delimitier="\t";
    }
    while (($counter>0)&&($data=fgetcsv($file,0,$delimitier,$enclosure,$escapeCharacter))){
    ///while (($counter>0)&&($data=fgetcsv($file,0,$delimitier,$enclosure))){ //pro starší verze PHP
      if ($counter==$count){
        $counter--;
        continue;
      }
      $outputArr[]=$data;
      $counter--;
    }
    fclose($file);
    return $outputArr;
  }

  /**
   * Funkce vracející počet řádků v CSV souboru
   * @param string $filename
   * @param string $delimitier = ','
   * @param string $enclosure  = '"'
   * @param string $escapeCharacter = '\\'
   * @return string[]
   */
  public static function getColsNamesInCsv($filename,$delimitier=',',$enclosure='"',$escapeCharacter='\\'){
    $columnNames=self::getRowsFromCSV($filename,1,$delimitier,$enclosure,$escapeCharacter);
    for ($i=count($columnNames)-1;$i>=0;$i++){
      if (Strings::trim($columnNames[$i])==''){
        unset($columnNames[$i]);
      }
    }
    return $columnNames;
  }

  /**
   * Funkce vracející počet řádků v CSV souboru
   * @param string $filename
   * @param string $delimitier = ','
   * @param string $enclosure  = '"'
   * @param string $escapeCharacter = '\\'
   * @return int
   */
  public static function getColsCountInCsv($filename,$delimitier=',',$enclosure='"',$escapeCharacter='\\'){
    return count(self::getColsNamesInCsv($filename,$delimitier,$enclosure,$escapeCharacter));
  }

  /**
   * Funkce vracející počet řádků v CSV souboru
   * @param string $filename
   * @return int
   */
  public static function getRowsCountInCsv($filename){
    $file=fopen($filename,'r');
    $rowsCount=0;
    while (fgets($file)){
      $rowsCount++;
    }
    fclose($file);
    return $rowsCount-1;
  }

  /**
   * Funkce vracející oddělovač, který je pravděpodobně použit v CSV souboru
   * @param string $filename
   * @return string
   */
  public static function getCSVDelimitier($filename){
    $file=fopen($filename,'r');
    if ($file===false){return ',';}
    if ($row=fgets($file)){
      $stredniky=substr_count($row,';');
      $carky=substr_count($row,',');
      $svislitka=substr_count($row,'|');
      $max=max($stredniky,$carky,$svislitka);
      if ($max<2){return ',';}
      switch ($max) {
        case $stredniky:return ';';
        case $carky:return ',';
        case $svislitka:return '|';
      }
    }
    fclose($file);
    return ',';
  }


  /**
   * Funkce vracející informace o datových sloupcích obsažených v CSV souboru
   * @param string $filename
   * @param string $delimitier = ','
   * @param string $enclosure  = '"'
   * @param string $escapeCharacter = '\\'
   * @return DbColumn[]
   */
  public static function analyzeCSVColumns($filename, $delimitier=',',$enclosure='"',$escapeCharacter='\\'){
    $columnNamesArr=self::getColsNamesInCsv($filename,$delimitier,$enclosure,$escapeCharacter);

    $numericalArr=array();
    $strlenArr=array();
    //výchozí inicializace počítacích polí
    $columnsCount=count($columnNamesArr);
    for($i=0;$i<$columnsCount;$i++){
      $numericalArr[$i]=true;
      $strlenArr[$i]=0;
    }

    $file=fopen($filename,'r');
    //kontrola všech řádků v souboru
    $rowsCount=0;
    while (($data=fgetcsv($file,0,$delimitier,$enclosure,$escapeCharacter))&&($rowsCount<10000)){
      //načten další řádek
      for ($i=0;$i<$columnsCount;$i++){
        $value=@$data[$i];
        $isNumeric=self::checkIsNumeric($value);
        if ($numericalArr[$i]==1){
          $numericalArr[$i]=$isNumeric;
        }elseif($numericalArr[$i]==2){
          if ($isNumeric==0){
            $numericalArr[$i]=0;
          }
        }
        $strlen=strlen($value);
        if ($strlen>$strlenArr[$i]){
          $strlenArr[$i]=$strlen;
        }
      }
      $rowsCount++;
    }
    //shromáždíme informace
    $outputArr=array();
    for ($i=0;$i<$columnsCount;$i++){
      if ($numericalArr[$i]==2){
        $datatype=DbColumn::TYPE_FLOAT;
      }elseif ($numericalArr[$i]==1){
        $datatype=DbColumn::TYPE_INTEGER;
      }else{
        $datatype=DbColumn::TYPE_STRING;
      }
      $dbColumn=new DbColumn();
      $dbColumn->dataType=$datatype;
      $dbColumn->strLength=$strlenArr[$i];
      $dbColumn->name=$columnNamesArr[$i];
      $outputArr[$i]=$dbColumn;
    }
    fclose($file);
    return $outputArr;
  }

  /**
   * Funkce pro kontrolu, jestli je daná hodnota číslem
   * @param string|int $value
   * @return int - 0=string, 1=int, 2=float
   */
  private static function checkIsNumeric($value){
    if (is_numeric($value)||(is_numeric(str_replace(',','.',$value)))){
      if (((string)intval(str_replace(',','.',$value)))==$value){
        return 1;
      }else{
        return 2;
      }
    }else{
      return 0;
    }
  }
} 