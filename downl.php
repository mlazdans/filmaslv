<?php

error_reporting(E_ALL);
ini_set('max_execution_time', 0);

if($argc < 2){
	print "Usage: $argv[0] <MovieID>\n";
	exit(1);
}

$MovieID = $argv[1];

$DEV = false;
$MainURL = "https://www.filmas.lv/movie/$MovieID/";
//$agent = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.87 Safari/537.36';
$agent = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:62.0) Gecko/20100101 Firefox/62.0';

$cu = curl_init();
curl_setopt($cu, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($cu, CURLOPT_AUTOREFERER, true);
curl_setopt($cu, CURLOPT_REFERER, $MainURL);
//curl_setopt($cu, CURLOPT_VERBOSE, true);
curl_setopt($cu, CURLOPT_RETURNTRANSFER, true);
curl_setopt($cu, CURLOPT_USERAGENT, $agent);
curl_setopt($cu, CURLOPT_COOKIEJAR, tempnam('', 'coo'));
curl_setopt($cu, CURLOPT_FOLLOWLOCATION, true);

function cget($cu, $url){
	global $DEV;

	if($DEV){
		return file_get_contents($url);
	}

	// sleep(1);
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

	print "PL=$PlaylistURL!\n";

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

if($DEV)$MainURL = "url/main.htm";
if(!($HTML = cget($cu, $MainURL))){
	print "Nevar ielādēt $MainURL\n";
	exit(1);
}

$MovieName = '';
//<meta name="title" content="Baltu Ciltis (2018)" />
//<meta name="title" content="Lidojam!? Cikls "Munks un Lemijs" (1994)" />
if(preg_match('/meta\s+name="title"\s+content="(.*)" \/>/i', $HTML, $m)){
	$MovieName = $m[1];
	$MovieName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $MovieName);
	$MovieName = mb_ereg_replace("([\.]{2,})", '', $MovieName);
	$MovieName = mb_convert_encoding($MovieName, "ISO-8859-13");
}

$main_f = tempnam('', 'mai');
$key_f = tempnam('', 'key');
$play_list_f = tempnam('', 'ply');

# Debug
file_put_contents($main_f, $HTML);

# Key request
if(!$DEV){
	$KeyReqURL = "https://www.filmas.lv/lmdb/hls/key/request/$MovieID?".time();
	print "Key request: $KeyReqURL\n";
	cget($cu, $KeyReqURL);

	$KeyReqURL = "https://www.filmas.lv/lmdb/hls/key/request/$MovieID?".time();
	print "2nd key request: $KeyReqURL\n";
	cget($cu, $KeyReqURL);
}

$Source = null;
//lmdb.video_src = "/lmdb/hls/playlist/314D1B939C3AB0904089247C4E7066CC.m3u8";
if(preg_match('/(\/lmdb\/hls\/playlist\/([A-Z0-9]*)\.m3u8)/', $HTML, $m)){
	$Source = 'filmaslv';
	if($DEV){
		$BestURL = get_best_playlist($cu, "url/$MovieID.m3u8");
	} else {
		$BestURL = get_best_playlist($cu, "https://www.filmas.lv".$m[1]);
	}
//lmdb.video_src = "https://ff0000.latnet.media/FilmasLV/Liekam_but_,540p,240p,720p,1080p,.mp4.urlset/master.m3u8";
} elseif(preg_match('/(https?:\/\/.*latnet\.media\/FilmasLV\/.*\/)master\.m3u8/', $HTML, $m)){
	$Source = 'latnet';
	$SourceURL = $m[1];
	if($DEV){
		$BestURL = get_best_playlist($cu, "url/$MovieID.m3u8");
	} else {
		$BestURL = $SourceURL.get_best_playlist($cu, $m[0]);
	}
//lmdb.video_src = "https://as2cdn.azureedge.net/Filmas/vanadzins_,512Kbps_360p,360Kbps_240p,1000Kbps_480p,2500Kbps_720p,_filmasLV.mp4.urlset/master.m3u8";
} elseif(preg_match('/(https?:\/\/.*azureedge\.net\/Filmas\/.*\/)master\.m3u8/', $HTML, $m)){
	$Source = 'azure';
	$SourceURL = $m[1];
	$BestURL = $SourceURL.get_best_playlist($cu, $m[0]);
//lmdb.trailer_src = "https://as2.filmas.lv/Trailers/,Diendusa_fragments.mov,.mp4.urlset/master.m3u8";
} elseif(preg_match('/(https?:\/\/.*\.filmas.lv\/Trailers\/.*\/)master\.m3u8/', $HTML, $m)){
	$Source = 'trailers';
	$SourceURL = $m[1];
	$BestURL = get_best_playlist($cu, $m[0]);
} else {
	print $HTML;
	print "Nevar sameklēt playlist\n";
	exit(1);
}

if($BestURL){
	print "Labākās kvalitātes playlist:\n$BestURL\n\n";
} else {
	print "Nevar sameklēt labāko playlist\n";
	exit(1);
}

if($DEV)$BestURL = "url/index-f4-v1-a1.m3u8";
if(!($PlaylistHTML = cget($cu, $BestURL))){
	print "Nevar ielādēt $BestURL\n";
	exit(1);
}

$PlaylistHTML = preg_split("/\r\n|\n|\r/", $PlaylistHTML);

$KeyURL = false;
for($i = 1; $i < count($PlaylistHTML); $i++){
	$parts = explode(':', trim($PlaylistHTML[$i]));
	$val = join(":", array_slice($parts, 1));
	if($parts[0] == '#EXT-X-KEY'){
		$patt = '/,URI="([^"]*)"/';
		if(preg_match($patt, $val, $m)){
			$PlaylistHTML[$i] = preg_replace($patt, sprintf(',URI="%s"', str_replace('\\', '/', $key_f)), $PlaylistHTML[$i]);
			if($Source == 'filmaslv'){
				$KeyURL = $m[1];
			} elseif($Source == 'latnet'){
				$KeyURL = $SourceURL.$m[1];
			} elseif($Source == 'azure'){
				$KeyURL = $SourceURL.$m[1];
			} elseif($Source == 'trailers'){
				$KeyURL = $m[1];
			}
			// break;
		} else {
			print "Nevar noparsēt KEY\n";
			exit(1);
		}
	}

	if($PlaylistHTML[$i] && (substr($PlaylistHTML[$i], 0, 1) != '#')){
		if($Source == 'latnet'){
			$PlaylistHTML[$i] = $SourceURL.$PlaylistHTML[$i];
		} elseif($Source == 'azure'){
			$PlaylistHTML[$i] = $SourceURL.$PlaylistHTML[$i];
		}
	}
}

if($KeyURL){
	print "KEY URL:\n$KeyURL\n\n";
} else {
	print "Nevar sameklēt KEY URL\n";
	exit(1);
}

if($DEV)$KeyURL = "url/key";

if($KEY = cget($cu, $KeyURL)){
	// Iespējams binary
	if(strlen($KEY) == 16){
		print "KEY: [binary] (iespējams)\n";
	} else {
		print "KEY: $KEY\n";
		$KEY = base64_decode($KEY);
	}
} else {
	print "Nevar ielādēt $KeyURL\n";
	exit(1);
}

if(false === file_put_contents($key_f, $KEY)){
	print "Nevar saglabāt KEY ($key_f)\n";
	exit(1);
}

if(false === file_put_contents($play_list_f, join("\n", $PlaylistHTML))){
	print "Nevar saglabāt playlist ($play_list_f)\n";
	exit(1);
}

$output_f = ($MovieName ? $MovieName."-" : "")."$MovieID.ts";

$args = [
	'-allowed_extensions', 'ALL',
	'-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
	'-i', escapeshellarg($play_list_f),
	'-c', 'copy', escapeshellarg(strip_path($output_f))
];

$cmdline = proc_prepare_args('ffmpeg', $args);

print "Mēģinam palaist ffmpeg\n";

exec($cmdline, $stdout, $errcode);

if($errcode){
	print "Nevar palaist ffmpeg\n";
	exit(1);
}
