<?php
/**
 * IMAP Datasource
 *
 * Notes:
 *   read() is working, when $this->ImapModel->find('all') is called. But
 *   $this->ImapModel->delete($id) does not call the delete() method on the
 *   datasource. However, read() and calculate() are called.
 *
 * Implementation reference: http://bin.cakephp.org/view/154300048
 *
 * Original implementations by: Moose and Gwoo.
 *
 * @package cakephp_imap_datasource
 */
class ImapSource extends DataSource {

/**
 * Description
 *
 * @var string
 */
	public $description = 'Imap Data Source';

/**
 * Default configuration.
 *
 * @var array
 */
	public $_baseConfig = array(
		'host' => 'localhost',
		'connect' => 'imap/notls',
		'login' => 'root',
		'password' => '',
		'mailbox' => 'INBOX',
		'port' => '143'
	);

/**
 * Default array of field list for imap mailbox.
 *
 * @var array
 */
	protected $_fields = array(
		'subject',
		'from',
		'to',
		'date',
		'message_id',
		'references',
		'in_reply_to',
		'size',
		'uid',
		'msgno',
		'recent',
		'flagged',
		'answered',
		'deleted',
		'seen',
		'draft',
		'body',
		'attachment'
	);

//  protected $_schema = array(
//          example:
//          'tweets' => array(
//                          'id' => array(
//                                          'type' => 'integer',
//                                          'null' => true,
//                                          'key' => 'primary',
//                                          'length' => 11,
//                          ),
//                          'text' => array(
//                                          'type' => 'string',
//                                          'null' => true,
//                                          'key' => 'primary',
//                                          'length' => 140
//                          ),
//                          'status' => array(
//                                          'type' => 'string',
//                                          'null' => true,
//                                          'key' => 'primary',
//                                          'length' => 140
//                          ),
//          ),
//          'emails' => array(
//            'subject' => array(),
//            'from' => array(),
//            'to'  =>  array(),
//            'date'  =>  array(),
//            'message_id'  => array(),
//            'references'  => array(),
//            'in_reply_to' =>  array(),
//            'size'  =>    array(),
//            'uid' =>  array(),
//            'msgno' =>  array(),
//            'recent'  => array(),
//            'flagged' => array(),
//            'answered'  => array(),
//            'deleted' =>  array(),
//            'seen'  =>  array(),
//            'draft' =>  array()
//          )
//  );

/**
 * Flag to keep in mind, if only find('count') is executed.
 *
 * @var boolean
 */
	public $countonly = false;

/**
 * Global vars for decoding a message
 */
	public $htmlmsg;
	public $plainmsg;
	public $charset;
	public $attachments;

/**
 * Constructor
 */
	public function __construct($config = null, $autoConnect = true) {
		$this->debug = Configure::read('debug') > 0;
		$this->fullDebug = Configure::read('debug') > 1;
		// debug($config);
		parent::__construct($config);

		if ($autoConnect) {
			return $this->connect();
		} else {
			return true;
		}
	}

/**
 * Destructor
 */
	public function  __destruct() {
		if ($this->isConnected()) {
			$this->close();
		}
	}

/**
 * Connects to the mailbox using options in the given configuration array.
 *
 * @return boolean True if the mailbox could be connected, else false
 */
	public function connect() {
		$config = $this->config;
		$this->connected = false;

		$this->connection  = imap_open("{{$config['host']}:{$config['port']}/{$config['connect']}}{$config['mailbox']}", $config['login'], $config['password']);
		if ($this->connection) {
			// debug('connect');
			$this->connected = true;
		}
		return $this->connected;
	}

/**
 * Reconnects to database server with optional new settings
 *
 * @param array $config An array defining the new configuration settings
 * @return boolean True on success, false on failure
 */
	public function reconnect($config = null) {
		$this->disconnect();
		if ($config != null) {
			$this->config = am($this->_baseConfig, $config);
		}
		return $this->connect();
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	public function lastError() {
		if ($lastError = imap_last_error()) {
			$this->errors = imap_errors();
			$this->connected = false;
			return $lastError;
		}
		return false;
	}

/**
 * Delete
 *
 * @created: 15.10.2008 18:35:38
 * @param type $var
 *
 */
	public function delete(&$model, $id = null) {
		if ($this->connected) {
			if ($id == null && $model->id) {
				$id = $model->id;
			}
			if ($id) { 
				imap_delete($this->connection, $id, FT_UID);
				imap_expunge($this->connection);
				return true;
			}
		}
		return false;
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	public function read(&$model, $queryData = array(), $recursive = null) {
		$queryData = $this->__scrubQueryData($queryData);
		if ($this->connected) {
			$mc = imap_check($this->connection);
			$mc = min($mc->Nmsgs, $queryData['limit']);

			if (!isset($queryData['options'])) {
				$queryData['options'] = 0;
			}
			if (!isset($queryData['search'])) {
				$queryData['search'] = null;
			}

			$imapOrder = array();
			list($imapOrder['criteria'], $imapOrder['reverse']) = $this->_imapOrderFormat($queryData['order']);

			$resultSet = imap_sort($this->connection, $imapOrder['criteria'], $imapOrder['reverse'], $queryData['options'], $queryData['search'], $this->charset);
			$result = array();
			for ($i = 0; $i < $mc; $i++) {
				$result_tmp = imap_fetch_overview($this->connection, $resultSet[$i]);
				$result[] = $result_tmp[0];
			}
			$result = $this->_imapFormat($model, $queryData, $result);
			return $result;
		} else {
			return false;
		}
		return $resultSet;
	}

/**
 * Convert from simple field to imapSort order criteria
 *
 * order fields: 'message_date|date', 'arrival_date', 'from_address', 'subject', 'to_address', 'cc_address', 'size'
 *
 * @param array() $order
 * @return array()
 */
	private function _imapOrderFormat($order = null) {
		$allowedOrderFields = array(
			'message_date',
			'date',
			'arrival_date',
			'from_address',
			'subject',
			'to_address',
			'cc_address',
			'size'
		);

		if (!$order) {
			return array(SORTDATE, 0);
		}

		$criteria = 'date';
		$reverse  = 0;

		if (is_array($order[0])) {
			$keys = array_keys($order[0]);
			if (is_int($keys[0])) {
				$criteria = $order[0][$keys[0]];
			} else {
				$criteria = low($keys[0]);
				$reverse = low($order[0][$keys[0]]) == 'asc' ? 0 : 1;
			}
		} else {
			$criteria = low($order[0]);
		}

		if (!in_array($criteria, $allowedOrderFields)) {
			return array(SORTDATE, 0);
		}

		switch ($criteria) {
			case 'message_date':
			case 'date':
				return array(SORTDATE, $reverse);
				break;

			case 'arrival_date':
				return array(SORTARRIVAL, $reverse);
				break;

			case 'from_address':
				return array(SORTFROM, $reverse);
				break;

			case 'subject ':
				return array(SORTSUBJECT, $reverse);
				break;

			case 'to_address':
				return array(SORTTO, $reverse);
				break;

			case 'cc_address':
				return array(SORTCC, $reverse);
				break;

			case 'size':
				return array(SORTSIZE, $reverse);
				break;

			default:
				return array(SORTDATE, 0);
		}
	}

	private function _imapFormat($model, $queryData, $data) {
		$res = array();
		$count = count($data);
		if (isset($queryData['fields']) && !empty($queryData['fields'])) {
			$fields = $queryData['fields'];
		} else {
			$fields = $this->_fields;
		}

		$hasBody = $this->checkBody($queryData);
		$hasAttachment = $this->checkAttachment($queryData);
		//debug($hasAttachment);
		//debug($hasBody);
		$i = 0;
		foreach ($data as $d) {
			// transfer the fields
			$res[$i][$model->alias]['id'] = $d->msgno;
			foreach ($fields as $field) {
				if ($field != 'body') {
					if (!empty($d->{$field})) {
						$res[$i][$model->alias][$field] = $d->{$field};
					}
				}

				if ($hasBody) {
					$this->getmsg($this->connection, $d->msgno);
					if (!empty($this->htmlmsg)) {
						$res[$i][$model->alias]['htmlmsg'] = $this->htmlmsg;
					}
					if (!empty($this->plainmsg)) {
						$res[$i][$model->alias]['plainmsg'] = $this->plainmsg;
					}
					if ($hasAttachment) {
						if (!empty($this->attachments)) {
							$res[$i][$model->alias]['attachments'] = $this->attachments;
						}
					}
					if (!empty($this->charset)) {
						$res[$i][$model->alias]['charset'] = $this->charset;
					}
				}
			}
			$i++;
		}
		return $res;
	}

	private function checkBody($queryData) {
		if (isset($queryData['fields']) && !empty($queryData['fields'])) {
			if (in_array('body', $queryData['fields'])) {
				return true;
			}
		}
		if ((isset($queryData['fields']) && empty($queryData['fields'])) || !isset($queryData['fields'])) {
			return true;
		}
		return false;
	}

	private function checkAttachment($queryData) {
		if (isset($queryData['fields']) && !empty($queryData['fields'])) {
			if (in_array('attachments', $queryData['fields'])) {
				return true;
			}
		}
		if ((isset($queryData['fields']) && empty($queryData['fields'])) || !isset($queryData['fields'])) {
			return true;
		}
		return false;
	}

	function calculate(&$model, $func, $params = array()) {
		debug('calculate:'.$func);
		if ($func == 'count') {
			$this->countonly = true;
			$this->numRows = imap_num_msg($this->connection);
			//debug($this->numRows);
			return $this->numRows;
		} else {
			debug($func);
		}
		return false;
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	function query() {
		// imap_
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	function describe() {
		//debug('describe');
		$mailbox = imap_check($this->connection);

		if ($mailbox) {
			$mbox["Date"]    = $mailbox->Date;
			$mbox["Driver"]  = $mailbox->Driver;
			$mbox["Mailbox"] = $mailbox->Mailbox;
			$mbox["Messages"]= $mailbox->Nmsgs;
			$mbox["Recent"]  = $this->numRecent();
			// set numRows
			$this->numRows = $mailbox->Nmsgs;
		}
		//debug($mbox);
		return $mbox;
	}

/**
 * Number of Total Emails
 */
	function numMessages() {
		//debug('numMessages');
		return imap_num_msg($this->connection);
	}

/**
 * Number of Recent Emails
 */
	function numRecent() {
		//debug('numRecent');
		return imap_num_recent($this->connection);
	}

/**
 * Caches/returns cached results for child instances
 *
 * @return array
 */
	function listSources() {
		if ($this->cacheSources === false) {
			return null;
		}

		if ($this->_sources !== null) {
			return $this->_sources;
		}

		$sources = imap_getmailboxes($this->connection, "{{$config['host']}}", "*");

		$this->_sources = $sources;
		return $sources;
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	function column() {
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	function isConnected() {
		return $this->connected;
	}

/**
 * Disconnects from mailbox.
 *
 * @return boolean True if the database could be disconnected, else false
 */
	function disconnect() {
		$this->connected = !@imap_close($this->connection);
		return !$this->connected;
	}

/**
 * Disconnects database, kills the connection and says the connection is closed,
 * and if DEBUG is turned on, the log for this object is shown.
 *
 */
	function close() {
		if ($this->fullDebug) {
			$this->showLog();
		}
		$this->disconnect();
	}

/**
 * undocumented function
 *
 * @return void
 * @author gwoo
 */
	function showLog() {
	}

	function __scrubQueryData($data) {
		foreach (array('conditions', 'fields', 'order', 'limit', 'offset', 'group') as $key) {
			if (!isset($data[$key]) || empty($data[$key])) {
				$data[$key] = array();
			}
		}
		return $data;
	}

	private function getmsg($mbox,$mid) {
		// input $mbox = IMAP stream, $mid = message id
		// output all the following:
		// global $this->htmlmsg,$this->plainmsg,$this->charset,$this->attachments;
		// the message may in $this->htmlmsg, $this->plainmsg, or both
		$this->htmlmsg = '';
		$this->plainmsg = '';
		$this->charset = '';
		$this->attachments = array();

		// HEADER - we have it already
		// $h = imap_header($mbox,$mid);
		// add code here to get date, from, to, cc, subject...

		// BODY
		$s = imap_fetchstructure($mbox, $mid);
		if (isset($s->parts)) {
			foreach ($s->parts as $partno0 => $p) {
				$this->getpart($mbox, $mid, $p, $partno0 + 1);
			}
		} else {
			$this->getpart($mbox, $mid, $s, 0);  // no part-number, so pass 0
		}
	}

	private function getpart($mbox, $mid, $p, $partno) {
		// $partno = '1', '2', '2.1', '2.1.3', etc if multipart, 0 if not multipart
		// global $htmlmsg,$plainmsg,$charset,$attachments;

		// DECODE DATA
		$data = ($partno) ?
			imap_fetchbody($mbox, $mid, $partno) : // multipart
			imap_body($mbox, $mid); // not multipart

		// Any part may be encoded, even plain text messages, so check everything.
		if ($p->encoding == 4) {
			$data = quoted_printable_decode($data);
		} elseif ($p->encoding == 3) {
			$data = base64_decode($data);
		}
		// no need to decode 7-bit, 8-bit, or binary

		// PARAMETERS
		// get all parameters, like charset, filenames of attachments, etc.
		$params = array();
		if (isset($p->parameters)) {
			if ($p->parameters) {
				foreach ($p->parameters as $x) {
					$params[strtolower($x->attribute)] = $x->value;
				}
			}
			if (isset($p->dparameters)) {
				foreach ($p->dparameters as $x) {
					$params[strtolower($x->attribute)] = $x->value;
				}
			}
		}

		if (!empty($params['filename']) || !empty($params['name'])) {
			// ATTACHMENT
			// Any part with a filename is an attachment,
			// so an attached text file (type 0) is not mistaken as the message.
			// filename may be given as 'Filename' or 'Name' or both
			$filename = ($params['filename']) ? $params['filename'] : $params['name'];
			// filename may be encoded, so see imap_mime_header_decode()
			$this->attachments[$filename] = $data;  // this is a problem if two files have same name
			// TODO: check for double filenames
			//debug($this->attachments);
		} elseif ($p->type == 0 && $data) {
			// TEXT
			// Messages may be split in different parts because of inline attachments,
			// so append parts together with blank row.
			if (strtolower($p->subtype)=='plain') {
				$this->plainmsg .= trim($data) ."\n\n";
			} else {
				$this->htmlmsg .= $data . "<br/><br/>";
			}
			if (!empty($params['charset'])) {
				$this->charset = $params['charset'];  // assume all parts are same charset
			}
		} elseif ($p->type == 2 && $data) {
		    // EMBEDDED MESSAGE
		    // Many bounce notifications embed the original message as type 2,
		    // but AOL uses type 1 (multipart), which is not handled here.
		    // There are no PHP functions to parse embedded messages,
		    // so this just appends the raw source to the main message.
			$this->plainmsg .= trim($data) ."\n\n";
		}

		// SUBPART RECURSION
		if (isset($p->parts)) {
			foreach ($p->parts as $partno0 => $p2) {
				$this->getpart($mbox, $mid, $p2, $partno . '.' . ($partno0 + 1));  // 1.2, 1.2.1, etc.
			}
		}
	}
}
?>