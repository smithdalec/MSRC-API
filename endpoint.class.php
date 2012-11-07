<?php

class MSRCEndpointHelper {

	/**
	 * Array of data to represent in the JSON output
	 * @var array
	 */
	protected $data = array();

	/**
	 * Standard metadata to send back in the JSON output. Includes UNIX
	 * timestamp of the request, the full URI, and the query string
	 * @var array
	 */
	protected $metadata = array();

	/**
	 * Array of errors to return. If errors are present, data will not display
	 * @var array
	 */
	protected $errors = array();


	/**
	 * Constructor
	 */
	function __construct()
	{
		$this->metadata = array(
			'request_time' => REQUEST_TIME,
			'uri'          => $_SERVER['HTTP_HOST'] . '/' . request_uri(),
			'query_string' => $_SERVER['QUERY_STRING'],
		);
	}

	/**
	 * Print out JSON data for a request
	 */
	protected function outputJSON()
	{
		if (!empty($this->errors)) {
			$errors = array('errors' => $this->errors);
		}
		else {
			$errors = array();
		}

		$output = array_merge(
			$this->metadata,
			$errors,
			$this->data);

		drupal_json_output($output);
	}
}


////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////


class MSRCSingleRecordHelper extends MSRCEndpointHelper {

	/**
	 * Biblio ID of the Record to display
	 * @var integer
	 */
	private $bid = 0;

	/**
	 * Constructor
	 */
	public function __construct($bid)
	{
		parent::__construct();
		$this->bid = $bid;
	}

	/**
	 * Display a single Biblio entity in JSON format
	 */
	public function showRecord()
	{
		// Create biblio entity
		$biblio = biblio_load($this->bid);
		// Send 404 if invalid ID was given
		if (!$biblio) return drupal_not_found();
		$this->data['document'] = $biblio;
		// Output the JSON
		$this->outputJSON();
	}

	private function getFieldMap()
	{
		// drupal_field_name => JSON_field_name
		$map = array(

		);
	}
}


////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////


class MSRCListHelper extends MSRCEndpointHelper {

	/**
	 * Show all Biblio IDs based upon query paramaters
	 */
	public function showList()
	{
		$query_params = $this->getQueryParams();

		// Get all biblio entities that match our query string params
		$query = new EntityFieldQuery();
		$query->entityCondition('entity_type', 'biblio');
		foreach ($query_params as $field => $value) {
			$query->fieldCondition($field, 'value', $value, 'like');
		}

		$result = reset($query->execute());

		// Create an array of IDs from the result
		foreach ($result as $entity_data) {
			$this->data['document_ids'][] = $entity_data->bid;
		}

		$this->outputJSON();
	}

	/**
	 * Get query paramaters from URL and filter out any invalid fields in the
	 * query string
	 * @return array Array of query paramaters whose keys are drupal fields
	 */
	private function getQueryParams()
	{
		$query_params = drupal_get_query_parameters();
		$fields = field_info_fields();

		// We don't want non-existent fields in our query, or exceptions will be thrown
		foreach($query_params as $field => $value) {
			if (!isset($fields[$field])) {
				unset($query_params[$field]);
				$this->errors[] = 'Invalid field: ' . $field;
			}
		}

		return $query_params;
	}
}