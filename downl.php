<?php

error_reporting(E_ALL);

require_once("filmaslv.lib.php");

ini_set('max_execution_time', 0);

$A = getopt("im:p:", [], $rest);
$MovieID = $A['m']??0;
$PlayListURL = $A['p']??'';
$ShowInfo = isset($A['i']);

if(!$MovieID && !$PlayListURL){
	print "Usage: $argv[0] [-i] [-m <MovieID> | -p <PlayListURL>]\n";
	print "\t-i parādīt tikai info\n";
	exit(1);
}

$agent = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:62.0) Gecko/20100101 Firefox/62.0';

$cu = curl_init();
curl_setopt($cu, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($cu, CURLOPT_AUTOREFERER, true);
//curl_setopt($cu, CURLOPT_REFERER, $MainURL);
//curl_setopt($cu, CURLOPT_VERBOSE, true);
curl_setopt($cu, CURLOPT_RETURNTRANSFER, true);
curl_setopt($cu, CURLOPT_USERAGENT, $agent);
curl_setopt($cu, CURLOPT_COOKIEJAR, tempnam('', 'coo'));
curl_setopt($cu, CURLOPT_FOLLOWLOCATION, true);

$MovieName = '';
if($MovieID){
	$MainURL = "https://www.filmas.lv/movie/$MovieID/";

	if(!($HTML = cget($cu, $MainURL))){
		print "Nevar ielādēt $MainURL\n";
		exit(1);
	}

	# <meta name="title" content="Baltu Ciltis (2018)" />
	# <meta name="title" content="Lidojam!? Cikls "Munks un Lemijs" (1994)" />
	if(preg_match('/meta\s+name="title"\s+content="(.*)" \/>/i', $HTML, $m)){
		$MovieName = $m[1];
	}

	# Key request
	$KeyReqURL = "https://www.filmas.lv/lmdb/hls/key/request/$MovieID?".time();
	print "Key request: $KeyReqURL\n";
	cget($cu, $KeyReqURL);

	$KeyReqURL = "https://www.filmas.lv/lmdb/hls/key/request/$MovieID?".time();
	print "2nd key request: $KeyReqURL\n";
	cget($cu, $KeyReqURL);
	$Hey = $HTML;
} else {
	$Hey = $PlayListURL;
}

$Source = null;
# lmdb.video_src = "/lmdb/hls/playlist/314D1B939C3AB0904089247C4E7066CC.m3u8";
if(preg_match('/(\/lmdb\/hls\/playlist\/([A-Z0-9]*)\.m3u8)/', $Hey, $m)){
	$Source = 'filmaslv';
	$PL = "https://www.filmas.lv".$m[1];
	$TempName = $m[2];
	$BestURL = get_best_playlist($cu, $PL);
# lmdb.video_src = "https://ff0000.latnet.media/FilmasLV/Liekam_but_,540p,240p,720p,1080p,.mp4.urlset/master.m3u8";
# lmdb.video_src = "https://as2lv.filmas.lv/FilmasLV/hlsnkc/,20221025/0/0_qfr8jsoz_0_5o9d4zdj_2.mp4,20221025/0/0_qfr8jsoz_0_85zi16cr_2.mp4,20221025/0/0_qfr8jsoz_0_wghgcwpz_12.mp4,.urlset/master.m3u8";
} elseif(preg_match('/(https?:\/\/.*\/FilmasLV\/(.*)\/)master\.m3u8/', $Hey, $m)){
	$Source = 'latnet';
	$PL = $m[0];
	$SourceURL = $m[1];
	$TempName = $m[2];
	$BestURL = $SourceURL.get_best_playlist($cu, $PL);
# lmdb.video_src = "https://as2cdn.azureedge.net/Filmas/vanadzins_,512Kbps_360p,360Kbps_240p,1000Kbps_480p,2500Kbps_720p,_filmasLV.mp4.urlset/master.m3u8";
} elseif(preg_match('/(https?:\/\/.*azureedge\.net\/Filmas\/(.*)\/)master\.m3u8/', $Hey, $m)){
	$Source = 'azure';
	$PL = $m[0];
	$SourceURL = $m[1];
	$TempName = $m[2];
	$BestURL = $SourceURL.get_best_playlist($cu, $PL);
# lmdb.trailer_src = "https://as2.filmas.lv/Trailers/,Diendusa_fragments.mov,.mp4.urlset/master.m3u8";
} elseif(preg_match('/(https?:\/\/.*\.filmas.lv\/Trailers\/(.*)\/)master\.m3u8/', $Hey, $m)){
	$Source = 'trailers';
	$PL = $m[0];
	$SourceURL = $m[1];
	$TempName = $m[2];
	$BestURL = get_best_playlist($cu, $PL);
} else {
	print "Nevar sameklēt playlist\n";
	exit(1);
}

if(!$MovieName){
	$temp_parts = explode(',', $TempName);
	$MovieName = $temp_parts[0];
}

if(!$MovieName){
	$MovieName = substr(md5(time()), 0, 10);
}

$MovieName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $MovieName);
$MovieName = mb_ereg_replace("([\.]{2,})", '', $MovieName);
$MovieName = mb_convert_encoding($MovieName, "UTF-8");

print "Faila nosaukums: $MovieName\n";

print "Playlist URL: $PL!\n";
print "Pieejamie playlist:\n".trim(cget($cu, $PL))."\n";

if($BestURL){
	print "Labākās kvalitātes playlist: $BestURL\n";
} else {
	print "Nevar sameklēt labāko playlist\n";
	exit(1);
}

if($ShowInfo){
	exit;
}

if(!($PlaylistHTML = cget($cu, $BestURL))){
	print "Nevar ielādēt $BestURL\n";
	exit(1);
}

$key_f = tempnam('', 'key');
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
	if($KEY = cget($cu, $KeyURL)){
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
} else {
	print "Nevar sameklēt KEY URL. Mēģinām bez!\n";
}

# NOTE: test, ja nav uzstādīts $MovieID
curl_setopt($cu, CURLOPT_REFERER, "https://www.filmas.lv/movie/$MovieID/");

$play_list_f = tempnam('', 'ply');
if(false === file_put_contents($play_list_f, join("\n", $PlaylistHTML))){
	print "Nevar saglabāt playlist ($play_list_f)\n";
	exit(1);
}

$output_f = $MovieName.($MovieID ? "-$MovieID" : "").".ts";

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
