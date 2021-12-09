<?php

function cget($cu, $url){
	curl_setopt($cu, CURLOPT_URL, $url);
	return curl_exec($cu);
}

function escape_shell(Array $args){
	foreach($args as $k=>$part){
		if(is_string($k)){
			$params[] = escapeshellarg($k).'='.escapeshellarg($part);
		} else {
			$params[] = escapeshellarg($part);
		}
	}
	return $args;
}

function proc_prepare_args($cmd, $args = []){
	if($args){
		$cmd .= ' '.join(" ", escape_shell($args));
	}
	return $cmd;
}

function strip_path($path) {
	return preg_replace('/[:\/\\\]/', '_', $path);
}

function get_best_playlist($cu, $PlaylistURL){
	if(!($PlaylistHTML = cget($cu, $PlaylistURL))){
		print "Nevar ielādēt $PlaylistURL\n";
		exit(1);
	}

	$PlaylistHTML = preg_split("/\r\n|\n|\r/", $PlaylistHTML);

	$max = -1;
	$BestURL = null;
	for($i = 1; $i < count($PlaylistHTML); $i+=2){
		$params = explode(',', trim($PlaylistHTML[$i]));
		foreach($params as $p){
			$parts = explode("=", $p);
			if(strtoupper($parts[0]) == 'BANDWIDTH'){
				if($parts[1]>$max){
					$max = $parts[1];
					$BestURL = trim($PlaylistHTML[$i + 1]);
				}
			}
		}
	}

	return $BestURL;
}
