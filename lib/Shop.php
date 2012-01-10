<?php

require_once("MasterClass.php");

/**
 * A generic shop class to inherit some useful functions from.
 *
 * @author Lars Kumbier
 */
abstract class Shop extends Masterclass
{
	/**
	 * Fetches the reponse from an url
	 *
	 * @param  string $url
	 * @throws Exception
	 * @return string
	 */
	protected function fetchHtmlFromUrl($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		if ($response === false)
			throw new Exception ("Could not fetch URL: ".curl_error($ch));
		return $response;
	}
		

	/**
	 * Grabs a complete div-structure from some starting point
	 * 
	 * @param  string $startPregexp
	 * @param  string $html
	 * @throws Exception
	 * @return (false|string)
	 */
	protected function extractDivFromHtml($startPregexp, $html) {
		if (preg_match($startPregexp, $html, $matches) == 0) {
			return false;
		}
		$divLvl = 1;
		
		$openPos  = stripos($html, $matches[0]);
		$curPos   = $openPos+strlen($matches[0]);
		$closePos = null;
		
		do {
			unset($matches);
			preg_match('/<\/div>/i', $html, $matches, 0, $curPos);
			$closePos = stripos($html, $matches[0], $curPos);
			
			unset($matches);
			if (preg_match('/<div/i', $html, $matches, 0, $curPos))
				$nextOpenPos = strpos($html, $matches[0], $curPos);
			else
				$nextOpenPos = strlen($html);
			
			if ($closePos < $nextOpenPos) {
				$divLvl--;
				$curPos = $closePos+4;
			} else {
				$divLvl++;
				$curPos = $nextOpenPos+4;
			}
		} while ($divLvl > 0);
		
		if ($divLvl > 0)
			throw new Exception('Did not find a matching close-div-tag for the'.
			                    ' starting div.');
		
		return substr($html, $openPos, $closePos - $openPos + 6);
	}
}
