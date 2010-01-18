<?php
// model example
class AdwordsEmail extends AppModel {

  public $name = 'AdwordsEmail';
  public $useDbConfig = 'reportsEmail';
  public $useTable = false;

  /**
   * Model::exists()  have to return true
   */
  function exists($reset = false) {
    return true;
  }

}
?>
