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
			'uri'          => $_SERVER['HTTP_HOST'] . request_uri(),
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
		foreach ($this->fieldMap as $set => $fields) {
			$fields_arr = (array) $fields;
			$flipped_map = array_flip($fields_arr);
			if (isset($flipped_map[$JSON_field])) {
				return $flipped_map[$JSON_field];
			}
		}
	}
}


////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////


class MSRCSingleRecordHelper extends MSRCEndpointHelper {

	/**
	 * Entity Metadata Wrapper for our Biblio entity
	 * Useful for easy getting/setting of entity property values
	 * @var object
	 */
	private $wrapper;

	/**
	 * Constructor
	 */
	public function __construct($bid)
	{
		parent::__construct();

		$biblio = biblio_load($bid);
		// Send 404 if invalid ID was given
		if (!$biblio) return drupal_not_found();

		$this->wrapper = biblio_wrapper($biblio);
	}

	/**
	 * Display a single Biblio entity in JSON format
	 */
	public function showRecord()
	{
		foreach ($this->fieldMap as $field_type => $field_map) {
			foreach ($field_map as $drupal_field => $json_field) {
				$value = $this->getValue($this->wrapper->$drupal_field->value());
				if ($value) {
					$this->modifyValue($json_field, $value);
					$mapped_data[$field_type][$json_field] = $value;
				}
			}
		}

		// Set the data structure
		$this->data['record'] = $mapped_data['record'];
		$this->data['record']['document'] = $mapped_data['document'];

		$this->setAdditionalFields();

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

	/**
	 * Change any field values prior to output
	 * @param  string $property The name of the property to change
	 * @param  string $value    The original value of the property. Passed by
	 *                          reference, and will be modified in the function.
	 */
	private function modifyValue($property, &$value)
	{
		switch ($property) {
			// Convert timestamps to ISO-8601 - (e.g. 1999-07-01T19:30:45+10:00)
			case 'created':
			case 'changed':
			case 'startDate':
			case 'endDate':
				$value = date('Y-m-d\TG:i:sP', $value);
				break;
			case 'id':
				$value = 'http://msrc.idiginfo.org/documents/' . $value;
				break;
			default:
				# code...
				break;
		}
	}

	/**
	 * Set any extra fields to the JSON output that aren't already set by
	 * Drupal via fields/entity properties
	 */
	private function setAdditionalFields()
	{
		// Set Contributor IDs
		foreach($this->wrapper->biblio_primary_contributors as $contributor) {
			$cid = $contributor->cid->value();
			$creator = 'http://msrc.idiginfo.org/creators/' . $cid;
			$this->data['record']['creators'][] = $creator;
		}

		// Set A.nnotate URL
		$attachment = $this->wrapper->field_attatchment->value();
		$attach_set = isset($attachment[0]['fid']);
		if ($attachment && $attach_set && $fid = $attachment[0]['fid']) {
			if ($url = $this->getAnnotateID($fid))
			$this->data['record']['annotations'] = $url;
		}

		// Set Document ID
		if ($doi = $this->wrapper->biblio_doi->value()) {
			$id = 'doi:' . $doi;
		} else {
			$bid = $this->wrapper->bid->value();
			$id = 'http://msrc.idiginfo.org/documents/' . $bid . '/pub';
		}
		$this->data['record']['document']['id']  = $id;
	}

	/**
	 * Get the multi-valued id of an a_nnotate document corresponding to a local
	 * file
	 * @param  integer $fid
	 * @return array      The a_nnotate ID, keyed by c and d
	 */
	private function getAnnotateID($fid)
	{
		if (!module_exists('a_nnotate')) return FALSE;

		$a_nnotate_sync = new A_nnotate_sync_records;
		if (!$a_nnotate_sync->get_sync_record_from_fid($fid)) {
			return FALSE;
		}

		//Get the Sync Record (with A.nnotate Document ID)
		$sync_rec = _a_nnotate_sync_entity($fid);

		// c and d are part of a_nnotate's odd multi-valued unique identifier
		return array('c' => $sync_rec->aid->c, 'd' => $sync_rec->aid->d);
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
		$drupalized_params = array();
		$fields = field_info_fields();

		// We don't want non-existent fields in our query, or exceptions will be thrown
		foreach($query_params as $field => $value) {
			$drupal_field = $this->getDrupalField($field);
			if (isset($fields[$drupal_field])) {
				$drupalized_params[$drupal_field] = $value;
			} else {
				$this->errors[] = 'Invalid field: ' . $field;
			}
		}

		return $drupalized_params;
	}
}