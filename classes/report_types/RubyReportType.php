<?php
class RubyReportType extends ReportTypeBase {
        public static $log;

	public static function init(&$report) {
	        self::$log = fopen("/tmp/rubyreport.log","a");


		$report->raw_query = "#REPORT: ".$report->report."\n".trim($report->raw_query);
		
		//if there are any included reports, add it to the top of the raw query
		if(isset($report->options['Includes'])) {
			$included_code = '';
			foreach($report->options['Includes'] as &$included_report) {
				$included_code .= "# BEGIN INCLUDED REPORT\n".trim($included_report->raw_query)."\n# END INCLUDED REPORT\n";
			}
			
			if($included_code) $included_code.= "\n";
			
			$report->raw_query = $included_code . $report->raw_query;
		}
	}
	
	public static function openConnection(&$report) {
		
	}
	
	public static function closeConnection(&$report) {
		
	}
	
	public static function log($msg) {
	       fwrite(self::$log, "$msg\n");
	}
	public static function run(&$report) {		
		self::log("-------------- Starting up...\n");
		$ruby = '';
		$ruby .= 'conf.echo = false' . "\n";
		$ruby .= 'Airbrake.add_filter(&:ignore!)' . "\n";
		$ruby .= 'conf.return_format = ""' . "\n";
		$ruby .= 'ActiveRecord::Base.logger = nil' . "\n";
		$ruby .= 'def put_json(obj) puts ">>> START JSON"; puts obj.to_json; puts ">>> END JSON"; end' . "\n";
		$ruby .= "\n";

		$debug = false;
		foreach($report->macros as $key=>$value) {
			if ( $key == "debug" && $value ) {
			   $debug = true;
			}
			if(is_array($value)) {
				$value = json_encode($value);
			}
			else {
				$value = '"'.addslashes($value).'"';
			}
			
			$ruby .= $key.' = '.$value.';'."\n";
		}

		$ruby .= $report->raw_query;

		$environments = PhpReports::$config['environments'];
		$config = $environments[$report->options['Environment']][$report->options['Database']];

		//command without ruby string
		$command = $config['cmd'];

		self::log("Command string: " . $command);
		
		$report->options['Query_Formatted'] = '<div>
			<pre style="background-color: black; color: white; padding: 10px 5px;">$ ' . $command . '</pre>'.
			'Ruby String:'.
			'<pre class="prettyprint linenums lang-js">'.htmlentities($ruby).'</pre>
		</div>';
		
		$fdspec = array(
		   0 => array("pipe","r"),
		   1 => array("pipe","w"),
		   2 => array("file", "/tmp/error-output.txt", "a")
//		   2 => array("pipe","w")
                );

		if ( $debug ) {
		    echo "<pre>";
		    print( "COMMAND:\n" . $command . "\n" );
		    print( "EVAL:\n" . $ruby . "\n" );
		    echo "</pre>";
		    flush();
		}

		// Launch the Rails console
		
		$process = proc_open($command, $fdspec, $pipes);

		// Write the sentinel and the report file to the rails console
		
		// Send the Ruby script to the rails console
		
		$ruby .= "\n#ENDREPORT\n";

		self::log("SENDING:\n" . $ruby);
		fwrite($pipes[0], $ruby);

		// Now read the results back from the Ruby script.  The output looks like this:
		//  (junk)
		//  >>> START JSON
		//  JSON results
		//  >>> END JSON
		
		// Skip results coming back until the sentinel
		
		while (($line = fgets($pipes[1])) !== false) {
		    self::log("RECEIVED: " . rtrim($line));
		    if ( preg_match('/^>>> START JSON/', $line) ) {
		        self::log("SENTINEL FOUND");
		        break;
		    }
		}
		$result = "";
		while (($line = fgets($pipes[1])) !== false) {
		    self::log("RECEIVED: " . rtrim($line));
		    if ( preg_match('/>>> END JSON/', $line) ) {
		       break;
		    }

		    if ( $line ) {
		        $result .= $line;
		    }
		    self::log("NOW HAVE " . strlen($result));
		}
		self::log("DONE");
		fclose($pipes[1]);
		
		fclose($pipes[0]);
		proc_close($process);
				
		$result = trim($result);
		
		$json = json_decode($result, true);

		if ( $debug ) {
		    echo "<pre>";
		    echo "JSON:\n";
		    print_r($result);
		    echo "\nDECODED:\n";
		    print_r($json);
		    echo "</pre>";
		}

		if($json === NULL) throw new Exception("Could not convert from JSON: " . $result);

		return $json;
	}
}
