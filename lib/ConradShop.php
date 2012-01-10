<?php

require_once("IShopAPI.php");
require_once("Shop.php");
require_once("ShopArticle.php");



/**
 * Implements the IShopAPI for the conrad online shop
 * 
 * @author Lars Kumbier
 * @see    http://www.conrad.de
 */
class ConradShop extends Shop implements IShopAPI {
	/**
	 * Holds the url for getting Articles by id
	 * @var string
	 */
	private $articleByIdUrl = null;
	
	/**
	 * The Distributor name
	 */
	const DISTRIBUTOR_NAME = "Conrad";
	
	/**
	 * A pregexp, if an article ID was wrong.
	 * @var string
	 */
	private $articleNotFoundPregexp = 
			'/leider konnten wir keinen artikel mit/i';
	
	/**
	 * A pregexp, where the detail page part is
	 * @var string
	 */
	private $detailPregexp = '/<div class="inner" id="details">/i';
	
	
	/**
	 * An array of detail div's pregexps
	 * @var array
	 */
	private $detailDivPregexps = array (
			'/<div id="mc_info_\d{4,8}_produktbezeichnung">/i',
			'/<div id="mc_info_\d{4,8}_highlights">/i',
			'/<div id="mc_info_\d{4,8}_beschreibung">/i',
			'/<div id="mc_info_\d{4,8}_special">/i',
			'/<div id="mc_info_\d{4,8}_technischedaten">/i');
	
	
	/**
	 * A pregexp for the attributes-part
	 * @var string
	 */
	private $attributesPregexp = 
			'/<div id="mc_info_\d{4,8}_technischedaten2">/i';
	
	
	/**
	 * A pregexp for datasheets
	 * @var string
	 */
	private $datasheetPregexp = '/<div class="inner" id="download-dokumente"/';
	
	
	
	/**
	 * Returns the Distributor Name.
	 */
	public function GetDistributorName() {
		return self::DISTRIBUTOR_NAME;
	}
	
	
	/**
	 * Searches an article by it's unique identifier in the shop in question.
	 * Returns a boolean false, if the article was not found, and an instance
	 * of an Article-Class, if found.
	 * 
	 * @param  string $id
	 * @return (false|ShopArticle)
	 */
	public function GetArticleById($id) {
		if (!$this->validateArticleId($id))
			die("Id did not validate");
		
		$callUrl = str_replace('{{id}}', $id, $this->articleByIdUrl);
		
		try {
			$htmlResponse = $this->fetchHtmlFromUrl($callUrl);
		} catch (Exception $e) {
			die("Error fetching the Url: ".$e->getMessage());
		}
		
		if (preg_match($this->articleNotFoundPregexp, $htmlResponse)) {
			return false;
		}
		
		$article = new ShopArticle();
		$article->Distributor = $this->GetDistributorName();
		$article->ArticleId   = $id;
		$article->ArticleUrl  = $callUrl;
		
		$this->extractPriceFromPagedata($htmlResponse, $article);
		$this->extractDescriptionFromPagedata($htmlResponse, $article);
		$this->extractAttributesFromPagedata($htmlResponse, $article);
		$this->extractDatasheetUrlsFromPagedata($htmlResponse, $article);
		
		return $article;
	}
	
	
	/**
	 * Simple stupid validation for Article ID
	 *
	 * @param  string $id
	 * @return bool
	 */
	private function validateArticleId($id) {
		if ((int)$id > 1000 && (int)$id < PHP_INT_MAX)
			return true;
		return false;
	}
	
	
	/**
	 * Extracts price and currency and sets this to a reference of a
	 * ShopArticle-Instance.
	 *
	 * @param string      $pagedata
	 * @param ShopArticle &$article
	 * @return bool       $success
	 */
	private function extractPriceFromPagedata($pagedata, 
	                                          ShopArticle &$article ) {
		preg_match('/<span id="mc_info_\d{4,8}_produktpreis">.{0,5}€ ?(\d{1,5},\d\d)/i', 
				$pagedata, $matches);
		
		if (empty($matches[1]))
			return false;
		
		$article->Currency = 'EUR';
		$article->Price    = (float)str_replace(',', '.', $matches[1]);
		
		return true;
	}
	
	
	/**
	 * Extracts the DatasheetUrls (if given) for a specific article
	 *
	 * @param string      $pagedata
	 * @param ShopArticle &$article
	 * @return bool       $success
	 */
	private function extractDatasheetUrlsFromPagedata($pagedata, 
	                                                  ShopArticle &$article ) {
		$data = $this->extractDivFromHtml($this->datasheetPregexp, $pagedata);
		
		if (empty($data))
			return false;
		
		$manuals = array();
		$curPos = 0;
		$curTitle = '';
		while(true) {
			$openPos = stripos($data, '<li>', $curPos);
			if ($openPos === false)
				break;
			
			$closePos = stripos($data, '</li>', $curPos+4);
			
			$curPos = $openPos+4;
			$part = substr($data, $openPos, $closePos-$openPos);
			
			$url = null;
			if (!stripos($part, '<a ')) {
				$curTitle = strip_tags($part);
			} else {
				$hrefOpenPos = stripos($part, ' href="') + 7;
				$hrefClosePos = stripos($part, '"', $hrefOpenPos);
				$url = substr($part, $hrefOpenPos, 
						$hrefClosePos - $hrefOpenPos);
			
				$curTitleName = $curTitle;
				if (array_key_exists($curTitle, $manuals)) {
					$i = 1;
					while (array_key_exists($curTitle.'_'.$i, $manuals))
						$i++;
					$curTitleName = $curTitle.'_'.$i;
				}
				$manuals[$curTitleName] = $url;
			}
			
			$curPos = $closePos+5;
		}
		$article->DatasheetUrls = $manuals;
		
		return true;
	}
	
	
	
	/**
	 * Extracs attributes from the html and returns it in a nice array
	 *
	 * @param string      $pagedata
	 * @param ShopArticle &$article
	 * @return (false|array)
	 */
	private function extractAttributesFromPagedata($pagedata,
	                                               ShopArticle &$article) {
		$data = $this->extractDivFromHtml($this->detailPregexp, $pagedata);
		if (!$data)
			return false;
		
		$data = $this->extractDivFromHtml($this->attributesPregexp, $data);
		if (!$data)
			return false;
		
		$attributes = array();
		$curPos = 0;
		while(true) {
			$openPos = stripos($data, '<th>', $curPos);
			if ($openPos === false)
				break;
			
			$closePos = stripos($data, '</th>', $curPos+4);
			$key = substr($data, $openPos+4, $closePos - $openPos - 4);
			
			$curPos = $closePos+5;
			
			$openPos = stripos($data, '<td>', $curPos);
			$closePos = stripos($data, '</td>', $curPos+4);
			$attributes[$key] = trim(substr($data, $openPos+4, 
					$closePos - $openPos - 4));
			
			$curPos = $closePos+5;
		}
		
		$article->Attributes = $attributes;
		return true;
	}
	
	
	
	/**
	 * Extracts the description html part from the homepage.
	 *
	 * @param  string      $pagedata
	 * @param  ShopArticle &$article
	 * @return bool
	 */
	private function extractDescriptionFromPagedata($pagedata,
	                                                ShopArticle &$article) {
		$data = $this->extractDivFromHtml($this->detailPregexp, $pagedata);
		if (!$data)
			return false;
		
		$description = '';
		foreach ($this->detailDivPregexps as $pregexp) {
			$part = $this->extractDivFromHtml($pregexp, $data);
			if ($part)
				$description .= $part;
		}
		
		$article->Description = $description;
		return true;
	}
	
	
	
	/**
	 * Setter for ArticleByIdUrl, does some validation
	 *
	 * @param  string $value
	 * @throws InvalidArgumentException
	 * @return bool
	 */
	public function SetArticleByIdUrl($value) {
		if (!is_string($value))
			throw new InvalidArgumentException(
					'ArticleByIdUrl needs to be a string.');
		
		if (!preg_match('/.*\{\{id\}\}.*/', $value))
			throw new InvalidArgumentException(
					'ArticleByIdUrl needs a "{{id}}"-tag - I don\'t know '.
					'where to set the id-value');
		
		$this->articleByIdUrl = $value;
		return true;
	}
}
