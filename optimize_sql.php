<?php

	// Command-line
	$TABLE; $DB; $USER; $PWD; $HOST;
	if ($argc != 11) {
		die("! FATAL: Invalid number of arguments (11)\n");
	} else {
		for ($t = 1; $t < $argc; $t++) {
			switch ($argv[$t]) {
			case "-t": $TABLE = $argv[$t + 1]; break;
			case "-d": $DB = $argv[$t + 1]; break;
			case "-u": $USER = $argv[$t + 1]; break;
			case "-p": $PWD = $argv[$t + 1]; break;
			case "-h": $HOST = $argv[$t + 1]; break;
			}
		}
		echo "Command line arguments pass.\n";
	}

	$con = mysql_connect($HOST, $USER, $PWD);

	if (!$con) { die('Could not connect: ' . mysql_error()); }

	$db_selected = mysql_select_db($DB, $con);

	$columns = mysql_query("SHOW COLUMNS FROM " . $TABLE, $con); 

	$count = 0;
	$empty_count = 0;
	$whitespace_count = 0;
	$length_count = 0;
	$field = array();
	$maxsize = array();
	$newtype = array();

	date_default_timezone_set('America/New_York');
	$start = date('m/d/Y h:i:s a', time());
	$f = fopen("optimize_sql_log.txt", "a");
	fwrite($f, $TABLE . "\n");	 
	fwrite($f, "STARTED: " . $TABLE . " at " . $start . "\n"); 	

	echo "Working on " . $TABLE . "...\n";

	while ($row = mysql_fetch_array($columns)) {
		array_push($field, $row[0]);          
		$count++;
	}

	for ($q = 0; $q < $count; $q++) {
		$max = 0;
		$ntype = "int";
		$result = mysql_query("SELECT `" . $field[$q] . "` FROM " . $TABLE, $con);

		mysql_query("UPDATE " . $TABLE . " SET " . $field[$q] . "=NULL WHERE CAST(" . $field[$q] . " AS CHAR)=''");

		while (($row = mysql_fetch_array($result)) !== FALSE) {

			if ((preg_match('/^\s+$/', $row[0]) == true) || $row[0] == NULL) {
				$curr = 1;
			} else {
				$curr = strlen($row[0]);			
			}		

			// Once it's set to a varchar, you can't unset to an int. 
			// If it's not a number, set it to varchar
			if (!is_numeric($row[0]) && $row[0] != NULL && (preg_match('/^\s+$/', $row[0])) !== 1) {
				$ntype = "varchar";
			}

			// If there is a decimal in the series of digits 
			if (preg_match('/^\d+\.\d+$/', $row[0]) !== 0) {
				$ntype = "varchar";
			}

			// Only if the leading zero is not alone
			if ((preg_match('/^0/', $row[0]) !== 0) && $row[0] !== "0") {
				$ntype = "varchar";
			}

			if ($curr > $max) {
				$max = $curr;
			}
		}

		array_push($newtype, $ntype);
		array_push($maxsize, $max);
	}

	for ($i = 0; $i < $count; $i++) {

		// Checks to ensure character max sizes are appropriate
		if ($newtype[$i] == "int" && $maxsize[$i] >= 10) {
			$var_field = "bigint" . "(" . $maxsize[$i] . ")";
		} else if ($newtype[$i] == "varchar" && $maxsize[$i] >= 255) {
			$var_field = "longtext";
		} else if ($maxsize[$i] == 255) {
			fwrite($f, "+ WARNING 255 Character Width: " . $TABLE . "--" . $field[$i] . "\n"); 
			$var_field = $newtype[$i] . "(" . $maxsize[$i] . ")";
		} else {
			$var_field = $newtype[$i] . "(" . $maxsize[$i] . ")";
		}
	
		mysql_query("ALTER TABLE " . $TABLE . " MODIFY " . $field[$i] . " " . $var_field);
		$length_count++;		
		echo "+ Altered " . $length_count . " of " . $count . " columns in " . $TABLE . "\n";

	}

	echo "Finished processing " . $TABLE . ".\n";
	$end = date('m/d/Y h:i:s a', time());
	fwrite($f, "COMPLETED: " . $TABLE . " at " . $end . "\n"); 
	fwrite($f, "--------------------\n\n");	
	fclose($f); 	
	mysql_close($con);

?>
