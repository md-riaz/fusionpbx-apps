<?php

	$y = 0;
	$apps[$x]['menu'][$y]['title']['en-us'] = 'Business Reports';
	$apps[$x]['menu'][$y]['title']['en-gb'] = 'Business Reports';
	$apps[$x]['menu'][$y]['uuid'] = 'a1b2c3d4-e5f6-7890-a1b2-c3d4e5f67890';
	$apps[$x]['menu'][$y]['parent_uuid'] = '0438b504-8613-7887-c420-c837ffb20cb1';
	$apps[$x]['menu'][$y]['category'] = 'internal';
	$apps[$x]['menu'][$y]['path'] = '/app/business_reports/dashboard.php';
	$apps[$x]['menu'][$y]['order'] = '100';
	$apps[$x]['menu'][$y]['groups'][] = 'superadmin';
	$apps[$x]['menu'][$y]['groups'][] = 'admin';
	$apps[$x]['menu'][$y]['groups'][] = 'user';

	$y++;
	$apps[$x]['menu'][$y]['title']['en-us'] = 'Diagnostics';
	$apps[$x]['menu'][$y]['title']['en-gb'] = 'Diagnostics';
	$apps[$x]['menu'][$y]['uuid'] = 'b2c3d4e5-f6a7-8901-b2c3-d4e5f6a78901';
	$apps[$x]['menu'][$y]['parent_uuid'] = 'a1b2c3d4-e5f6-7890-a1b2-c3d4e5f67890';
	$apps[$x]['menu'][$y]['category'] = 'internal';
	$apps[$x]['menu'][$y]['path'] = '/app/business_reports/diagnostics.php';
	$apps[$x]['menu'][$y]['order'] = '10';
	$apps[$x]['menu'][$y]['groups'][] = 'superadmin';

?>
