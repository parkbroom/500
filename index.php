<?php

function trimmy($str) {
	return trim($str, "\t\n\r\0\x0B\xC2\xA0");
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';

use Symfony\Component\DomCrawler\Crawler;

$metro = mysqli_connect("localhost","root","","metro");

//how many parellel connections at once
$limiter = 20;
//record to start at
$start = (int) file_get_contents("last_record.txt");

for ($n=$start; $n < 1000000; $n++) { 
	$from = $n;
	$to = $n + $limiter;
	echo $from . ' to ' . $to;
	echo "\n"; 
	$bbls = $metro->query("SELECT BORO, BLOCK, LOT, pkid FROM tc234 where pkid BETWEEN $from and $to ORDER BY pkid");
	// array of curl handles
	$multiCurl = array();
	// data to be returned
	$result = array();
	// multicurl handle
	$mh = curl_multi_init();

	$i = 0;
	$array = array();
	while ($row = $bbls->fetch_assoc()) {
		$boro = $row['BORO'];
		$block = str_pad($row['BLOCK'], 5, 0, STR_PAD_LEFT);
		$lot = str_pad($row['LOT'], 4, 0, STR_PAD_LEFT);

		$array[$i]['boro'] = $boro;
		$array[$i]['block'] = $block;
		$array[$i]['lot'] = $lot;
		$array[$i]['pkid'] = $row['pkid'];

		$multiCurl[$i] = curl_init();

		curl_setopt($multiCurl[$i], CURLOPT_URL, "https://a836-pts-access.nyc.gov/care/datalets/datalet.aspx?mode=acc_hist_det&UseSearch=no&pin=" . $boro . $block . $lot);
		curl_setopt($multiCurl[$i], CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($multiCurl[$i], CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($multiCurl[$i], CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($multiCurl[$i], CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($multiCurl[$i], CURLOPT_SSL_VERIFYHOST, false);

		$headers = array();
		$headers[] = 'Connection: keep-alive';
		$headers[] = 'Pragma: no-cache';
		$headers[] = 'Cache-Control: no-cache';
		$headers[] = 'Upgrade-Insecure-Requests: 1';
		$headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/74.0.3729.169 Safari/537.36';
		$headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3';
		$headers[] = 'Accept-Encoding: gzip, deflate, br';
		$headers[] = 'Accept-Language: en-US,en;q=0.9';
		curl_setopt($multiCurl[$i], CURLOPT_HTTPHEADER, $headers);
		curl_multi_add_handle($mh, $multiCurl[$i]);
		$i++;
	}

	$index=null;

	do {
	  curl_multi_exec($mh,$index);
	} while($index > 0);
	// get content and remove handles

	foreach ($array as $k => $row) {
		file_put_contents("last_record.txt", $row['pkid']);
		$result[$k] = curl_multi_getcontent($multiCurl[$k]);
		//may set faster 500000 = half sec
		//usleep(5000000);
		$crawler = new Crawler($result[$k]);
		$buildingClass = $crawler->filter('#Profile > tr:nth-child(1) > td.DataletData')->text();
		$taxClass = trimmy($crawler->filter('#Profile > tr:nth-child(2) > td.DataletData')->text());
		$scrieCredit = trimmy($crawler->filter('#Profile > tr:nth-child(3) > td.DataletData')->text());
		$drieCredit = trimmy($crawler->filter('#Profile > tr:nth-child(4) > td.DataletData')->text());
		$refundAmt = trimmy($crawler->filter('#Profile > tr:nth-child(5) > td.DataletData')->text());
		$overpaymentAmt = trimmy($crawler->filter('#Profile > tr:nth-child(6) > td.DataletData')->text());
		$metro->query("INSERT INTO dof_scrape_tc234 (
						Borough_Code,
						Tax_Block,
						Tax_Lot,
						building_class,
						tax_class,
						Unused_SCRIE_Credit,
						Unused_DRIE_Credit,
						refund_amount,
						overpayment_amount) VALUES ('$row[boro]', '$row[block]', '$row[lot]', '$buildingClass', '$taxClass', '$scrieCredit', '$drieCredit', '$refundAmt', '$overpaymentAmt')");
		curl_multi_remove_handle($mh, $multiCurl[$k]);
	}
	$n = $n + $limiter;
}
// close
//curl_multi_close($mh);
