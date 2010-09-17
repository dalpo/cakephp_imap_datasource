<?php
// model example
class MyEmail extends AppModel {

  public $name = 'MyEmail';
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
