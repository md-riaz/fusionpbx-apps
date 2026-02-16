<?php
/*
	FusionPBX - Business Reports
	Diagnostics: Configure CDR source and field mappings
*/

//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('business_report_diagnostics')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//load classes
	require_once "classes/diagnostics.php";

//get the database object
	$database = new database;

//initialize diagnostics
	$diagnostics = new report_diagnostics($database, $_SESSION['domain_uuid']);
	$db_type = $database->driver();

//handle form submission
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_config') {
		$cdr_source = $_POST['cdr_source'];
		$call_type_mode = $_POST['call_type_mode'];
		$counting_unit = $_POST['counting_unit'];
		$call_id_field = $_POST['call_id_field'];
		
		// Build field mapping from POST data
		$field_mapping = array();
		$logical_fields = array('domain_uuid', 'start_stamp', 'answer_stamp', 'end_stamp', 'duration', 'billsec', 
								'hangup_cause', 'uuid', 'caller_id_number', 'destination_number', 'direction', 
								'extension_uuid', 'gateway_uuid');
		
		foreach ($logical_fields as $field) {
			if (!empty($_POST['field_' . $field])) {
				$field_mapping[$field] = $_POST['field_' . $field];
			}
		}
		
		// Additional config
		$additional_config = array();
		if (!empty($_POST['internal_extension_pattern'])) {
			$additional_config['internal_extension_pattern'] = $_POST['internal_extension_pattern'];
		}
		
		$success = $diagnostics->save_config($cdr_source, $field_mapping, $call_type_mode, $counting_unit, $call_id_field, $additional_config);
		
		if ($success) {
			message::add('Configuration saved successfully', 'positive');
		} else {
			message::add('Error saving configuration', 'negative');
		}
		
		header("Location: diagnostics.php");
		exit;
	}

//discover CDR sources
	$cdr_sources = $diagnostics->find_cdr_sources();

//load existing config
	$config = $diagnostics->load_config();

//if a specific source is selected for inspection
	$selected_source = isset($_GET['inspect']) ? $_GET['inspect'] : ($config ? $config['cdr_source'] : null);
	$columns = array();
	$sample_data = array();
	$auto_mapping = array();
	$double_count_test = null;

	if ($selected_source) {
		$columns = $diagnostics->introspect_columns($selected_source);
		$sample_data = $diagnostics->get_sample_data($selected_source, 5);
		$auto_mapping = $diagnostics->auto_detect_field_mapping($selected_source);
		
		// Test for double counting
		$call_id_field = $config ? $config['call_id_field'] : (isset($auto_mapping['uuid']) ? $auto_mapping['uuid'] : 'uuid');
		$double_count_test = $diagnostics->test_double_counting($selected_source, $call_id_field, $_SESSION['domain_uuid']);
	}

//generate index recommendations
	$index_recommendations = array();
	if ($config && $selected_source) {
		$index_recommendations = $diagnostics->generate_index_recommendations($selected_source, $config['field_mapping']);
	}

//include the header
	require_once "resources/header.php";
	$document['title'] = $text['title-diagnostics'];

//show the content
	echo "<div class='action_bar sub'>\n";
	echo "	<div class='heading'><b>" . $text['title-diagnostics'] . "</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>'Back to Reports','icon'=>$_SESSION['theme']['button_icon_back'],'link'=>'dashboard.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

echo "<div style='margin: 20px;'>\n";

// Section 1: Database Type
echo "<h3>1. Database Type</h3>\n";
echo "<p><strong>Detected:</strong> " . htmlspecialchars($db_type) . "</p>\n";

// Section 2: CDR Sources
echo "<h3>2. CDR Sources Discovery</h3>\n";
if (count($cdr_sources) > 0) {
	echo "<p>Found " . count($cdr_sources) . " candidate CDR source(s):</p>\n";
	echo "<ul>\n";
	foreach ($cdr_sources as $source) {
		$inspect_url = "diagnostics.php?inspect=" . urlencode($source);
		$is_selected = ($source == $selected_source);
		echo "<li>";
		echo "<strong>" . htmlspecialchars($source) . "</strong> ";
		if ($is_selected) {
			echo "<span style='color: green;'>(Selected)</span>";
		} else {
			echo "- <a href='" . $inspect_url . "'>Inspect</a>";
		}
		echo "</li>\n";
	}
	echo "</ul>\n";
} else {
	echo "<p style='color: red;'>No CDR sources found. Please verify your database has CDR tables.</p>\n";
}

// Section 3: Column Introspection
if ($selected_source && count($columns) > 0) {
	echo "<h3>3. Column Introspection for: " . htmlspecialchars($selected_source) . "</h3>\n";
	echo "<p>Found " . count($columns) . " columns:</p>\n";
	echo "<table class='list'>\n";
	echo "<tr><th>Column Name</th><th>Data Type</th></tr>\n";
	foreach ($columns as $column) {
		echo "<tr><td>" . htmlspecialchars($column['name']) . "</td><td>" . htmlspecialchars($column['type']) . "</td></tr>\n";
	}
	echo "</table>\n";
}

// Section 4: Sample Data
if ($selected_source && count($sample_data) > 0) {
	echo "<h3>4. Sample Data (Latest 5 Records)</h3>\n";
	echo "<div style='overflow-x: auto;'>\n";
	echo "<table class='list' style='font-size: 0.9em;'>\n";
	echo "<tr>\n";
	foreach (array_keys($sample_data[0]) as $col_name) {
		echo "<th>" . htmlspecialchars($col_name) . "</th>\n";
	}
	echo "</tr>\n";
	foreach ($sample_data as $row) {
		echo "<tr>\n";
		foreach ($row as $value) {
			$display_value = strlen($value) > 30 ? substr($value, 0, 30) . '...' : $value;
			echo "<td>" . htmlspecialchars($display_value) . "</td>\n";
		}
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
}

// Section 5: Double Count Test
if ($double_count_test) {
	echo "<h3>5. Double Counting Test</h3>\n";
	echo "<p><strong>Total Rows:</strong> " . $double_count_test['row_count'] . "</p>\n";
	echo "<p><strong>Unique Calls:</strong> " . $double_count_test['unique_calls'] . "</p>\n";
	echo "<p><strong>Ratio:</strong> " . number_format($double_count_test['ratio'], 2) . "</p>\n";
	
	if ($double_count_test['likely_duplicates']) {
		echo "<p style='color: orange;'><strong>Warning:</strong> Likely duplicate legs detected. Consider using call-based counting.</p>\n";
	} else {
		echo "<p style='color: green;'>No significant duplication detected. Row-based counting is safe.</p>\n";
	}
}

// Section 6: Configuration Form
if ($selected_source) {
	echo "<h3>6. Configuration</h3>\n";
	echo "<form method='post' action='diagnostics.php'>\n";
	echo "<input type='hidden' name='action' value='save_config' />\n";
	
	echo "<table class='tr_hover' width='100%' cellpadding='0' cellspacing='0' border='0'>\n";
	
	// CDR Source
	echo "<tr>\n";
	echo "<td class='vncellreq' style='width: 30%;'>CDR Source Table:</td>\n";
	echo "<td class='vtable'>\n";
	echo "<input type='text' name='cdr_source' value='" . htmlspecialchars($selected_source) . "' required style='width: 300px;' />\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	// Field Mappings
	echo "<tr><td colspan='2'><strong>Field Mappings (Auto-detected):</strong></td></tr>\n";
	
	$logical_fields = array(
		'domain_uuid' => 'Domain UUID',
		'start_stamp' => 'Start Timestamp',
		'answer_stamp' => 'Answer Timestamp',
		'end_stamp' => 'End Timestamp',
		'duration' => 'Duration',
		'billsec' => 'Bill Seconds (Talk Time)',
		'hangup_cause' => 'Hangup Cause',
		'uuid' => 'Call UUID',
		'caller_id_number' => 'Caller ID Number',
		'destination_number' => 'Destination Number',
		'direction' => 'Direction',
		'extension_uuid' => 'Extension UUID',
		'gateway_uuid' => 'Gateway UUID'
	);
	
	foreach ($logical_fields as $field_key => $field_label) {
		$current_value = $config && isset($config['field_mapping'][$field_key]) ? 
						 $config['field_mapping'][$field_key] : 
						 (isset($auto_mapping[$field_key]) ? $auto_mapping[$field_key] : '');
		
		echo "<tr>\n";
		echo "<td class='vncell'>" . htmlspecialchars($field_label) . ":</td>\n";
		echo "<td class='vtable'>\n";
		echo "<input type='text' name='field_" . $field_key . "' value='" . htmlspecialchars($current_value) . "' style='width: 300px;' />\n";
		echo "</td>\n";
		echo "</tr>\n";
	}
	
	// Call Type Mode
	echo "<tr>\n";
	echo "<td class='vncellreq'>Call Type Classification Mode:</td>\n";
	echo "<td class='vtable'>\n";
	$current_mode = $config ? $config['call_type_mode'] : 'direction_field';
	echo "<select name='call_type_mode' required>\n";
	echo "<option value='direction_field'" . ($current_mode == 'direction_field' ? ' selected' : '') . ">Direction Field</option>\n";
	echo "<option value='gateway'" . ($current_mode == 'gateway' ? ' selected' : '') . ">Gateway Presence</option>\n";
	echo "<option value='pattern_match'" . ($current_mode == 'pattern_match' ? ' selected' : '') . ">Pattern Match</option>\n";
	echo "</select>\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	// Internal Extension Pattern (for pattern_match mode)
	echo "<tr>\n";
	echo "<td class='vncell'>Internal Extension Pattern:</td>\n";
	echo "<td class='vtable'>\n";
	$pattern_value = $config && isset($config['config']['internal_extension_pattern']) ? 
					 $config['config']['internal_extension_pattern'] : '';
	echo "<input type='text' name='internal_extension_pattern' value='" . htmlspecialchars($pattern_value) . "' placeholder='e.g., 1%' style='width: 300px;' />\n";
	echo "<br /><span class='vexpl'>SQL LIKE pattern for internal extensions (only used in pattern_match mode)</span>\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	// Counting Unit
	echo "<tr>\n";
	echo "<td class='vncellreq'>Counting Unit:</td>\n";
	echo "<td class='vtable'>\n";
	$current_unit = $config ? $config['counting_unit'] : 'row';
	echo "<select name='counting_unit' required>\n";
	echo "<option value='row'" . ($current_unit == 'row' ? ' selected' : '') . ">Row-based</option>\n";
	echo "<option value='call'" . ($current_unit == 'call' ? ' selected' : '') . ">Call-based (uses unique call ID)</option>\n";
	echo "</select>\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	// Call ID Field
	echo "<tr>\n";
	echo "<td class='vncellreq'>Call ID Field:</td>\n";
	echo "<td class='vtable'>\n";
	$current_call_id = $config ? $config['call_id_field'] : (isset($auto_mapping['uuid']) ? $auto_mapping['uuid'] : 'uuid');
	echo "<input type='text' name='call_id_field' value='" . htmlspecialchars($current_call_id) . "' required style='width: 300px;' />\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	echo "<tr>\n";
	echo "<td>&nbsp;</td>\n";
	echo "<td>\n";
	echo "<input type='submit' value='Save Configuration' class='btn' />\n";
	echo "</td>\n";
	echo "</tr>\n";
	
	echo "</table>\n";
	echo "</form>\n";
}

// Section 7: Index Recommendations
if (count($index_recommendations) > 0) {
	echo "<h3>7. Index Recommendations</h3>\n";
	echo "<p>Run these SQL commands to improve query performance:</p>\n";
	echo "<div style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; margin: 10px 0;'>\n";
	foreach ($index_recommendations as $recommendation) {
		echo "<pre style='margin: 5px 0; font-size: 0.9em;'>" . htmlspecialchars($recommendation['sql']) . ";</pre>\n";
	}
	echo "</div>\n";
	echo "<p><em>Note: These are recommendations only. Check if indexes already exist before creating them.</em></p>\n";
}

echo "</div>\n";

//include the footer
	require_once "resources/footer.php";

?>
