<?php
/*
	FusionPBX - Business Reports
	Diagnostics Class: Discovers CDR structure and field mappings
*/

class report_diagnostics {
	
	private $database;
	private $domain_uuid;
	private $db_type;
	
	public function __construct($database, $domain_uuid = null) {
		$this->database = $database;
		$this->domain_uuid = $domain_uuid;
		$this->db_type = $this->detect_db_type();
	}
	
	/**
	 * Detect database type
	 */
	private function detect_db_type() {
		$driver = $this->database->driver();
		if (strpos($driver, 'pgsql') !== false) {
			return 'postgresql';
		} elseif (strpos($driver, 'mysql') !== false) {
			return 'mysql';
		} elseif (strpos($driver, 'sqlite') !== false) {
			return 'sqlite';
		}
		return 'unknown';
	}
	
	/**
	 * Find candidate CDR sources
	 */
	public function find_cdr_sources() {
		$candidates = array('v_xml_cdr', 'v_cdr', 'cdr', 'xml_cdr');
		$found = array();
		
		foreach ($candidates as $table_name) {
			if ($this->table_exists($table_name)) {
				$found[] = $table_name;
			}
		}
		
		return $found;
	}
	
	/**
	 * Check if table exists
	 */
	private function table_exists($table_name) {
		try {
			if ($this->db_type == 'postgresql') {
				$sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = :table_name)";
			} else {
				$sql = "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = :table_name";
			}
			
			$params = array('table_name' => $table_name);
			$result = $this->database->execute($sql, $params);
			
			if ($result) {
				$row = $result->fetch(PDO::FETCH_ASSOC);
				if ($this->db_type == 'postgresql') {
					return $row['exists'] == 't';
				} else {
					return $row['count'] > 0;
				}
			}
		} catch (Exception $e) {
			return false;
		}
		return false;
	}
	
	/**
	 * Introspect columns for a given table
	 */
	public function introspect_columns($table_name) {
		$columns = array();
		
		try {
			$sql = "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = :table_name ORDER BY ordinal_position";
			$params = array('table_name' => $table_name);
			$result = $this->database->execute($sql, $params);
			
			if ($result) {
				while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
					$columns[] = array(
						'name' => $row['column_name'],
						'type' => $row['data_type']
					);
				}
			}
		} catch (Exception $e) {
			// Error introspecting columns
		}
		
		return $columns;
	}
	
	/**
	 * Get sample data from CDR source
	 */
	public function get_sample_data($table_name, $limit = 20) {
		$data = array();
		
		// Validate table_name against known CDR sources for security
		$valid_tables = $this->find_cdr_sources();
		if (!in_array($table_name, $valid_tables)) {
			return $data; // Invalid table name, return empty
		}
		
		// Check if start_stamp column exists for ordering
		$columns = $this->introspect_columns($table_name);
		$has_start_stamp = false;
		foreach ($columns as $col) {
			if ($col['name'] == 'start_stamp') {
				$has_start_stamp = true;
				break;
			}
		}
		
		try {
			// Properly escape table name by quoting it
			$quoted_table = $this->quote_identifier($table_name);
			$order_clause = $has_start_stamp ? " ORDER BY start_stamp DESC" : "";
			$sql = "SELECT * FROM " . $quoted_table . $order_clause . " LIMIT " . (int)$limit;
			$result = $this->database->execute($sql);
			
			if ($result) {
				while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
					$data[] = $row;
				}
			}
		} catch (Exception $e) {
			// Error getting sample data
		}
		
		return $data;
	}
	
	/**
	 * Auto-detect field mappings based on column names
	 */
	public function auto_detect_field_mapping($table_name) {
		$columns = $this->introspect_columns($table_name);
		$mapping = array();
		
		// Common field mappings
		$common_mappings = array(
			'domain_uuid' => array('domain_uuid', 'domain_id'),
			'start_stamp' => array('start_stamp', 'start_time', 'call_start_time'),
			'answer_stamp' => array('answer_stamp', 'answer_time', 'call_answer_time'),
			'end_stamp' => array('end_stamp', 'end_time', 'call_end_time'),
			'duration' => array('duration', 'call_duration'),
			'billsec' => array('billsec', 'bill_sec', 'talk_time', 'answered_time'),
			'hangup_cause' => array('hangup_cause', 'hangup_code', 'disconnect_cause'),
			'uuid' => array('uuid', 'call_uuid', 'id'),
			'caller_id_number' => array('caller_id_number', 'caller_number', 'ani'),
			'destination_number' => array('destination_number', 'called_number', 'dnis'),
			'direction' => array('direction', 'call_direction'),
			'extension_uuid' => array('extension_uuid', 'extension_id'),
			'gateway_uuid' => array('gateway_uuid', 'gateway_id')
		);
		
		foreach ($common_mappings as $logical_field => $possible_names) {
			foreach ($possible_names as $name) {
				foreach ($columns as $column) {
					if (strtolower($column['name']) == strtolower($name)) {
						$mapping[$logical_field] = $column['name'];
						break 2;
					}
				}
			}
		}
		
		return $mapping;
	}
	
	/**
	 * Test for double counting (multiple legs per call)
	 */
	public function test_double_counting($table_name, $call_id_field, $domain_uuid = null) {
		// Validate table_name
		$valid_tables = $this->find_cdr_sources();
		if (!in_array($table_name, $valid_tables)) {
			return null;
		}
		
		// Validate call_id_field against known columns
		$columns = $this->introspect_columns($table_name);
		$valid_field = false;
		foreach ($columns as $col) {
			if ($col['name'] == $call_id_field) {
				$valid_field = true;
				break;
			}
		}
		if (!$valid_field) {
			return null;
		}
		
		try {
			$where = "";
			$params = array();
			
			if ($domain_uuid) {
				$where = " WHERE domain_uuid = :domain_uuid";
				$params['domain_uuid'] = $domain_uuid;
			}
			
			$quoted_table = $this->quote_identifier($table_name);
			$quoted_field = $this->quote_identifier($call_id_field);
			
			$sql = "SELECT COUNT(*) as row_count, COUNT(DISTINCT " . $quoted_field . ") as unique_calls FROM " . $quoted_table . $where . " LIMIT 10000";
			$result = $this->database->execute($sql, $params);
			
			if ($result) {
				$row = $result->fetch(PDO::FETCH_ASSOC);
				$ratio = $row['unique_calls'] > 0 ? $row['row_count'] / $row['unique_calls'] : 1;
				
				return array(
					'row_count' => $row['row_count'],
					'unique_calls' => $row['unique_calls'],
					'ratio' => $ratio,
					'likely_duplicates' => $ratio > 1.1
				);
			}
		} catch (Exception $e) {
			// Error testing double counting
		}
		
		return null;
	}
	
	/**
	 * Save diagnostic configuration
	 */
	public function save_config($cdr_source, $field_mapping, $call_type_mode, $counting_unit, $call_id_field, $additional_config = array()) {
		try {
			// Check if config exists
			$sql = "SELECT report_diagnostic_uuid FROM v_report_diagnostics WHERE ";
			if ($this->domain_uuid) {
				$sql .= "domain_uuid = :domain_uuid";
				$params = array('domain_uuid' => $this->domain_uuid);
			} else {
				$sql .= "domain_uuid IS NULL";
				$params = array();
			}
			
			$result = $this->database->execute($sql, $params);
			$existing_uuid = null;
			
			if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
				$existing_uuid = $row['report_diagnostic_uuid'];
			}
			
			if ($existing_uuid) {
				// Update existing
				$sql = "UPDATE v_report_diagnostics SET cdr_source = :cdr_source, field_mapping_json = :field_mapping_json, ";
				$sql .= "call_type_mode = :call_type_mode, counting_unit = :counting_unit, call_id_field = :call_id_field, ";
				$sql .= "config_json = :config_json, updated_at = NOW() WHERE report_diagnostic_uuid = :uuid";
				
				$params = array(
					'uuid' => $existing_uuid,
					'cdr_source' => $cdr_source,
					'field_mapping_json' => json_encode($field_mapping),
					'call_type_mode' => $call_type_mode,
					'counting_unit' => $counting_unit,
					'call_id_field' => $call_id_field,
					'config_json' => json_encode($additional_config)
				);
			} else {
				// Insert new
				$new_uuid = uuid();
				$sql = "INSERT INTO v_report_diagnostics (report_diagnostic_uuid, domain_uuid, cdr_source, field_mapping_json, ";
				$sql .= "call_type_mode, counting_unit, call_id_field, config_json, updated_at) ";
				$sql .= "VALUES (:uuid, :domain_uuid, :cdr_source, :field_mapping_json, :call_type_mode, :counting_unit, ";
				$sql .= ":call_id_field, :config_json, NOW())";
				
				$params = array(
					'uuid' => $new_uuid,
					'domain_uuid' => $this->domain_uuid,
					'cdr_source' => $cdr_source,
					'field_mapping_json' => json_encode($field_mapping),
					'call_type_mode' => $call_type_mode,
					'counting_unit' => $counting_unit,
					'call_id_field' => $call_id_field,
					'config_json' => json_encode($additional_config)
				);
			}
			
			$this->database->execute($sql, $params);
			return true;
		} catch (Exception $e) {
			return false;
		}
	}
	
	/**
	 * Load diagnostic configuration
	 */
	public function load_config() {
		try {
			$sql = "SELECT * FROM v_report_diagnostics WHERE ";
			if ($this->domain_uuid) {
				$sql .= "domain_uuid = :domain_uuid";
				$params = array('domain_uuid' => $this->domain_uuid);
			} else {
				$sql .= "domain_uuid IS NULL";
				$params = array();
			}
			
			$result = $this->database->execute($sql, $params);
			
			if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
				return array(
					'cdr_source' => $row['cdr_source'],
					'field_mapping' => json_decode($row['field_mapping_json'], true),
					'call_type_mode' => $row['call_type_mode'],
					'counting_unit' => $row['counting_unit'],
					'call_id_field' => $row['call_id_field'],
					'config' => json_decode($row['config_json'], true)
				);
			}
		} catch (Exception $e) {
			// Error loading config
		}
		
		return null;
	}
	
	/**
	 * Generate index recommendations
	 */
	public function generate_index_recommendations($table_name, $field_mapping) {
		$recommendations = array();
		
		// Basic indexes
		$domain_field = isset($field_mapping['domain_uuid']) ? $field_mapping['domain_uuid'] : 'domain_uuid';
		$start_field = isset($field_mapping['start_stamp']) ? $field_mapping['start_stamp'] : 'start_stamp';
		
		$recommendations[] = array(
			'name' => 'idx_' . $table_name . '_domain_start',
			'sql' => 'CREATE INDEX idx_' . $table_name . '_domain_start ON ' . $table_name . ' (' . $domain_field . ', ' . $start_field . ')'
		);
		
		// Additional recommended indexes
		if (isset($field_mapping['billsec'])) {
			$recommendations[] = array(
				'name' => 'idx_' . $table_name . '_domain_start_billsec',
				'sql' => 'CREATE INDEX idx_' . $table_name . '_domain_start_billsec ON ' . $table_name . ' (' . $domain_field . ', ' . $start_field . ', ' . $field_mapping['billsec'] . ')'
			);
		}
		
		if (isset($field_mapping['hangup_cause'])) {
			$recommendations[] = array(
				'name' => 'idx_' . $table_name . '_domain_start_hangup',
				'sql' => 'CREATE INDEX idx_' . $table_name . '_domain_start_hangup ON ' . $table_name . ' (' . $domain_field . ', ' . $start_field . ', ' . $field_mapping['hangup_cause'] . ')'
			);
		}
		
		return $recommendations;
	}
	
	/**
	 * Quote identifier for SQL safety
	 */
	private function quote_identifier($identifier) {
		// Remove any dangerous characters and quote appropriately
		$identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
		
		if ($this->db_type == 'postgresql') {
			return '"' . $identifier . '"';
		} else {
			return '`' . $identifier . '`';
		}
	}
}

?>
