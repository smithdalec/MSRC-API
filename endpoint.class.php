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
	 * Mapping of drupal field names to JSON-friendly names
	 * Just a PHP array version of field_map.json
	 * @var array
	 */
	protected $fieldMap = array();


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

		// Put our JSON file of the field mapping in our fieldMap property
		$path = drupal_get_path('module', 'msrc_api') . '/field_map.json';
		$this->fieldMap = json_decode(file_get_contents($path));
	}

	/**
	 * Print out JSON data for a request
	 */
	protected function outputJSON()
	{
		if (!empty($this->errors))
			$errors = array('errors' => $this->errors);
		else $errors = array();

		$output = array_merge($this->metadata, $errors, $this->data);
		drupal_json_output($output);
	}

	/**
	 * Get the JSON-friendly field name associated with a Drupal field name
	 * in field_map.json
	 * @param  string $drupal_field The name of the Drupal field
	 * @return string               The name of the associated JSON field
	 */
	protected function getJSONField($drupal_field)
	{
		return $this->fieldMap[$drupal_field];
	}

	/**
	 * Get the Drupal field name associated with a JSON field name in
	 * field_map.json
	 * @param  string $JSON_field The name of the JSON field
	 * @return string             The name of the associated Drupal field
	 */
	protected function getDrupalField($JSON_field)
	{
		$flipped_map = array_flip($this->fieldMap);
		return $flipped_map[$JSON_field];
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

		// Set the metadata wrapper for easy getting/setting of entity values
		$wrapper = biblio_wrapper($biblio);

		foreach ($this->fieldMap as $drupal_field => $json_field) {
			$value = $this->getValue($wrapper->$drupal_field->value());
			if ($value) {
				$this->data['document'][$json_field] = $value;
			}
		}

		// Output the JSON
		$this->outputJSON();
	}

	/**
	 * Pulls the raw value of a given Drupal field structure. Particularly
	 * useful when getting the value of a multivalued/multi-keyed field
	 * @param  mixed $initial_value The original field structure or value
	 * @return mixed                Raw value, or array of raw values (if
	 *                                  a multivalued field was given)
	 */
	private function getValue($initial_value)
	{
		// We don't want to display empty values in our JSON output
		if (empty($initial_value)) return FALSE;

		if (is_array($initial_value)) {
			// Some Drupal field formatters keep the actual value in an array
			// key called 'value' that's an extra layer deep in the field array
			if (isset($initial_value['value'])) {
				$value = $initial_value['value'];
			}
			else {
				// We must be dealing with a multivalued property
				foreach ($initial_value as $atomic_value) {
					if (is_object($atomic_value)) {
						if (isset($atomic_value->vocabulary_machine_name)) {
							// We have a keyword
							$value[] = $atomic_value->name;
						}
						if (isset($atomic_value->biblio_contributor_name)) {
							// We have a biblio contributor entity
							$wrapper = biblio_wrapper($atomic_value, 'biblio_contributor');
							$value[] = $wrapper->biblio_contributor_name->value();
						}
					}
				}
			}
		}
		else {
			// Regular values (non-multivalued strings, integers, etc.)
			$value = $initial_value;
		}
		return $value;
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