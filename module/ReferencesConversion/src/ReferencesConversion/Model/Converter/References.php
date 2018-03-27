<?php

namespace ReferencesConversion\Model\Converter;

use Xmlps\Logger\Logger;
use Xmlps\Command\Command;
use Xmlps\Libxml\Libxml;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXpath;
use XSLTProcessor;

use Manager\Model\Converter\AbstractConverter;

/**
 * Parses references using the ParsCit citation parser
 */
class References extends AbstractConverter
{
    use Libxml;

    protected $config;
    protected $logger;

    protected $inputFile;
    protected $parsCitReferencesFile = null;
    protected $outputDirectory;
    protected $outputFile;

    protected $xml;
    protected $dom;
    protected $domXpath;

    protected $ridMappings = array();
    protected $replacedRidInBibtex = array();

    /**
     * Constructor
     *
     * @param mixed $config
     * @param Logger $logger Logger
     *
     * @return void
     */
    public function __construct($config, Logger $logger)
    {
        if (!isset($config['crossref_api']['endpoint'])) {
            throw new \Exception("CrossRef's api endpoint is not configured");
        }

        if (!isset($config['crossref_api']['score_threshold'])) {
            throw new \Exception('Api result score threshold is not configured');
        }

        $this->config = $config;
        $this->logger = $logger;

        $this->disableLibxmlErrorDisplay();
    }

    /**
     * Set the file to convert
     *
     * @param mixed $inputFile
     *
     * @return void
     */
    public function setInputFile($inputFile)
    {
        if (!file_exists($inputFile)) {
            throw new \Exception('Input file doesn\'t exist');
        }

        $this->inputFile = $inputFile;

        // Populate the XML attribute with the content from the file
        $this->loadXml();

        // Parse the DOM tree from the XML
        $this->parseDom();
    }

    /**
     * Set path to file for unknown references
     *
     * @param mixed $path
     *
     * @return void
     */
    public function setParsCitReferencesFilePath($path)
    {
        $this->parsCitReferencesFile = $path;
    }

    /**
     * Set the output directory for temporary conversion files
     *
     * @param mixed $outputDirectory
     *
     * @return void
     */
    public function setOutputDirectory($outputDirectory)
    {
        $this->outputDirectory = $outputDirectory;
    }

    /**
     * Sets the output file for the parsed references XNL
     *
     * @return void
     */
    public function setOutputFile($outputFile) {
        $this->outputFile = $outputFile;
    }

    /**
     * Loads the xml from the input file
     *
     * @return void
     */
    protected function loadXml()
    {
        $this->xml = file_get_contents($this->inputFile);
    }

    /**
     * Parses the DOM tree from the xml attribute
     *
     * @return void
     */
    protected function parseDom()
    {
        $this->dom = new DOMDocument();

        if (!$this->dom->loadXML($this->xml)) {
            $this->logger->debugTranslate(
                'referencesconversion.converter.loadXML.domErrorLog',
                $this->libxmlErrors()
            );

            return false;
        }

        $this->domXpath = new DOMXPath($this->dom);
    }

    /**
     * Parse the references
     *
     * @return void
     */
    public function convert()
    {
        if (is_null($this->parsCitReferencesFile)) {
            throw new \Exception('Path to file for unknown references not set');
        }

        $this->logger->debugTranslate('referencesconversion.converter.startLog');

        if (
            !($this->dom instanceof DOMDocument) or
            !($this->domXpath instanceof DOMXpath)
        ) {
            $this->logger->debugTranslate(
                'referencesconversion.converter.convert.inputErrorLog'
            );
            $this->status = false;
            return;
        }

        // Parse the bibliography
        $this->parseBibliography();

        // In case we replace some rid in the ref-list, update xref with new rids.
        if (!empty($this->replacedRidInBibtex)) {

			// update rid in xml document
			$xrefs = $this->domXpath->query('body//xref');
			foreach ($xrefs as $xref) {
				if (!$xref->hasAttribute('rid')) {
					continue;
				}

				$xrid = $xref->getAttribute('rid');
				if (!empty($xrid) && in_array($xrid, $this->replacedRidInBibtex)) {
					$xref->setAttribute('rid', "R{$this->ridMappings[$xrid]}");
				}
			}

			// and write changes to disk
			file_put_contents($this->inputFile, $this->dom->saveXML());
        }

        $this->status = true;
    }

    /**
     * Parse the bibliography from a document.
     *
     * @return DomNode CitationList dom node
     */
    protected function parseBibliography()
    {
        // Extract the bibliography from the input file
        $bibliography = $this->extractBibliography();

        // Create a temporary file for the parsCit command to process
        $referencesFile = $this->getReferenceFile($bibliography);

        // Process references
        $result = $this->processReferences($referencesFile);

        // Create bibtex file
        if (!empty($result['bibtex'])) {
            file_put_contents($this->outputFile, implode(PHP_EOL . PHP_EOL, $result['bibtex']) . PHP_EOL . PHP_EOL);
        }

        // if unknowns, save for parsCit job
        if (isset($result['unknowns']) && !empty($result['unknowns'])) {
            array_unshift($result['unknowns'], 'REFERENCES');
            $unknowns = implode(PHP_EOL . PHP_EOL, $result['unknowns']);

            if (!file_exists(dirname($this->parsCitReferencesFile))) {
                @mkdir(dirname($this->parsCitReferencesFile));
            }

            file_put_contents($this->parsCitReferencesFile, $unknowns);
        }
    }

    /**
     * Extract standard NLM bibliography from document. If standard NLM
     * biblography is not available fall back to TEI bibliography
     *
     * @return DOMNode Bibliography node
     */
    protected function extractBibliography()
    {
        // Fetch the NLM bibliography
        if (!$bibliography = $this->extractNlmBibliography()) {
            // Fall back to the TEI style
            $bibliography = $this->extractTeiBibliography();
        }

        if (!$bibliography) {
            $this->logger->debugTranslate(
                'referencesconversion.converter.parseBibliography.noBibliographyLog'
            );

            return false;
        }

        $bibliography = $bibliography->item(0);
        $this->stripBibliographyTitle($bibliography);

        return $bibliography;
    }

    /**
     * Extract standard NLM bibliography
     *
     * @return DOMNode Bibliography node
     */
    protected function extractNlmBibliography()
    {
        $this->logger->debugTranslate(
            'referencesconversion.converter.parseBibliography.nlmLog'
        );

        $bibliography = $this->domXpath->query('//ref-list');
        if (!$bibliography->length) return false;

        return $bibliography;
    }

    /**
     * Extract TEI bibliography
     *
     * @return DOMNode Bibliography node
     * TODO: Check if this works correctly. Not sure about the structure of a
     * TEI bibliography
     */
    protected function extractTeiBibliography()
    {
        $this->logger->debugTranslate(
            'referencesconversion.converter.parseBibliography.teiLog'
        );

        $bibliography = $this->domXpath->query('//sec');
        if (!$bibliography->length) { return false; }

        return $bibliography;
    }

    /**
     * Strip the title from the bibliography
     *
     * @param DOMNode $bibliography Bibliography
     *
     * @return void
     */
    protected function stripBibliographyTitle(DOMNode $bibliography)
    {
        $titles = $this->domXpath->query('/title', $bibliography);
        if (!$titles->length) { return false; }
        $title = $titles->item(0);
        $bibliography->removeChild($title);
    }

    /**
     * Creates a temporary reference file to be used with the parsCit command
     *
     * @param DOMNode $bibliography Bibliography file name to create a reference from
     * @return string Reference file name
     */
    protected function getReferenceFile($bibliography)
    {
        // If we got a bibliography DOM node prepare the content for ParsCit
        if ($bibliography !== false) {
            $referencesFile = $this->outputDirectory . '/referencesTmp.txt';

            // Convert bibliography DOM node to string
            $references = array('REFERENCES');
            $all_refs = $bibliography->getElementsByTagName('ref');
            $refs_count = $all_refs->length;

            foreach ($all_refs as $reference) {
                // Extract the reference id
                $rid = '';
                if ($reference->hasAttribute('id')) {
                    $rid = trim($reference->getAttribute('id'));
                }

                // check if rid is numeric 
                // if not numeric, replace by numeric or 
                // pandoc will fallback to author names and break
                if (!is_numeric($rid)) {
					$old_rid = $rid;
					$rid = ++$refs_count;

                    // remember previous rid
                    $this->ridMappings[$old_rid] = $rid;
                }

                $rid  .= '. ';

                // Extract the reference content
                $referenceText = preg_replace('/\s+/s', ' ', $reference->textContent);
                $referenceText = trim($referenceText);
                if (!empty($referenceText)) $references[] =  $rid .  $referenceText;
            }
            $references = implode(PHP_EOL . PHP_EOL, $references);

            // Save the references to a file
            file_put_contents($referencesFile, $references);
        }

        // Fall back to the XML file in case the bibliography couldn't be extracted
        else {
            $referencesFile = $this->inputFile;
        }

        return $referencesFile;
    }

    protected function processReferences($referencesFile)
    {
        if (!file_exists($referencesFile)) {
            return NULL;
        }

        $bibtex = array();
        $unknowns = array();
        $raw = file_get_contents($referencesFile);
        foreach(explode("\n", $raw) as $entry) {
            // ignore this line
            if (trim($entry) == 'REFERENCES') continue;
            
            $entry = trim($entry);
            if (empty($entry)) continue;
            $doi = $this->queryCrossRefAPIForDOI($entry);
            if (!is_null($doi)) {
                $bt = $this->queryCrossRefAPIForBibTex($doi);
                
                // replace id with original id
                $bt = $this->replaceGeneratedBibtexIDWithInitial($entry, $bt);
                $bibtex[] = $bt;
            }
            else {
                $unknowns[] = $entry;
            }
        }
        
        return array(
            'bibtex' => $bibtex,
            'unknowns' => $unknowns 
        );
        
        @unlink($referencesFile);
    }
    
    /**
     * Replace bibtex id with initial ID from nlxml
     *
     * @param string $reference reference from bibliography
     * @param string $bibtex Bibtex from crossref
     * @return string $bibtex
     */
    function replaceGeneratedBibtexIDWithInitial($reference, $bibtex)
    {
        if (!preg_match('/^(\d+)\.\s.*$/', $reference, $matches)) {
            return $bibtex;
        }

        $number = $matches[1];
        $initialID = "R{$number}";

        $matches = preg_split("/@(.*)[{(](.*),/U", $bibtex, 2, PREG_SPLIT_DELIM_CAPTURE);
        if (count($matches) == 1) {
            return $bibtex;
        }

        // keep track of ids which were replaced in bibtex
        $this->replacedRidInBibtex[] = array_search($number, $this->ridMappings);

        return '@' . $matches[1] . '{' . $initialID . ',' . $matches[3] . PHP_EOL . PHP_EOL;
    }

    /**
     * Query CrossRef's api
     *
     * @param string $reference reference from bibliography
     * @return string DOI
     */
    protected function queryCrossRefAPIForDOI($reference)
    {
        if (empty($reference)) {
            return NULL;
        }
    
        $uri = $this->config['crossref_api']['endpoint'] . "?q=" . urlencode($reference);

        // comply to crossref's good manners by including mailto parameter in the query
        $mailto = $this->config['crossref_api']['mailto'];
        if (!empty($mailto)) {
		$uri .= '&mailto=' . urlencode($mailto);
        }

        $this->logger->debugTranslate(
                'parsCit.converter.apiQueryLog',
                $uri
                );
    
        $curl = curl_init();
        curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $uri
        ));
    
        if ( ! $response = curl_exec($curl)) {
            return NULL;
        }
    
        curl_close($curl);
        $resultObj = json_decode($response);
    
        if (isset($resultObj[0])) {
            $first = $resultObj[0];
            if (isset($first->doi) && isset($first->score)
                    && (intval($first->score) >= $this->config['crossref_api']['score_threshold'])) {
                        return $first->doi;
                    }
        }
    
        return NULL;
    }
    
    protected function queryCrossRefAPIForBibTex($doiUrl)
    {
        if (empty($doiUrl)) {
            return NULL;
        }
        
        $this->logger->debugTranslate(
                'parsCit.converter.apiQueryLog',
                $doiUrl
                );
        
        $curl = curl_init();
        $headers = array("Accept: application/x-bibtex");
        curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $doiUrl,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => 1,
        ));
        
        if ( ! $response = curl_exec($curl)) {
            return NULL;
        }
        
        curl_close($curl);
        
        return $response;
    }
}
