<?php

namespace Webwings\Gettext\Translator;

use Leafo\ScssPhp\Formatter\Debug;
use Nette;
use Nette\InvalidStateException;
use Nette\Utils\Strings;
use Sepia\PoParser\Catalog\CatalogArray;
use Sepia\PoParser\Catalog\Entry;
use Sepia\PoParser\Catalog\Header;
use Sepia\PoParser\Parser;
use Sepia\PoParser\PoCompiler;
use Sepia\PoParser\SourceHandler\FileSystem;
use Tracy\Debugger;

/**
 * Class Gettext
 *
 * @package GettextTranslator
 * @property string $lang
 * @property-write bool $productionMode
 */
class Gettext implements Nette\Localization\ITranslator
{
    use Nette\SmartObject;

    /* @var string */

    public static $namespace = 'Webwings\Gettext\Translator-Gettext';

    /** @var array */
    protected $files = [];

    /** @var */
    protected $scanToFile = null;

    /** @var string */
    protected $lang;

    /** @var array */
    protected $dictionary = [];

    /** @var bool */
    private $productionMode;

    /** @var bool */
    private $loaded = false;

    /** @var Nette\Http\SessionSection */
    private $sessionStorage;

    /** @var Nette\Caching\Cache */
    private $cache;

    /** @var Nette\Http\Response */
    private $httpResponse;

    /** @var array */
    private $metadata;

    /** @var array { [ key => default ] } */
    private $metadataList = array(
        'Project-Id-Version' => '',
        'Report-Msgid-Bugs-To' => NULL,
        'POT-Creation-Date' => '',
        'Last-Translator' => '',
        'Language-Team' => '',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'Plural-Forms' => 'nplurals=3; plural=((n==1) ? 0 : (n>=2 && n<=4 ? 1 : 2));',
        'X-Poedit-Language' => NULL,
        'X-Poedit-Country' => NULL,
        'X-Poedit-SourceCharset' => NULL,
        'X-Poedit-KeywordsList' => NULL
    );

    const SCAN_FILE_SECTION =  'scan';

    public function __construct(Nette\Http\Session $session, Nette\Caching\IStorage $cacheStorage, Nette\Http\Response $httpResponse, $instanceTag = null)
    {
        $this->sessionStorage = $sessionStorage = $session->getSection(self::$namespace . ($instanceTag ? ('-' . $instanceTag) : ''));
        $this->cache = new Nette\Caching\Cache($cacheStorage, self::$namespace . ($instanceTag ? ('-' . $instanceTag) : ''));
        $this->httpResponse = $httpResponse;

        if (!isset($sessionStorage->newStrings) || !is_array($sessionStorage->newStrings)) {
            $sessionStorage->newStrings = [];
        }
    }

    /**
     * Add file to parse
     * @param string $dir
     * @param string $identifier
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function addFile($dir, $identifier)
    {
        if (isset($this->files[$identifier])) {
            throw new \InvalidArgumentException("Language file identified '$identifier' is already registered.");
        }

        if (is_dir($dir)) {
            $this->files[$identifier] = $dir;
        } else {
            throw new \InvalidArgumentException("Directory '$dir' doesn't exist.");
        }

        return $this;
    }

    /**
     * @param $dir
     * @return $this
     */
    public function setScanToFile($dir)
    {
        if (is_dir($dir)) {
            $this->scanToFile = $dir;
        } else {
            throw new \InvalidArgumentException("Directory '$dir' doesn't exist.");
        }
        $this->addFile($dir,self::SCAN_FILE_SECTION);
        return $this;
    }

    /**
     * Get current language
     * @return string
     * @throws InvalidStateException
     */
    public function getLang()
    {
        if (empty($this->lang)) {
            throw new InvalidStateException('Language must be defined.');
        }

        return $this->lang;
    }

    /**
     * Set new language
     * @return this
     */
    public function setLang($lang)
    {
        if (empty($lang)) {
            throw new InvalidStateException('Language must be nonempty string.');
        }

        if ($this->lang === $lang) {
            return;
        }

        $this->lang = $lang;
        $this->dictionary = [];
        $this->loaded = false;

        return $this;
    }

    /**
     * Set production mode (has influence on cache usage)
     * @param bool $mode
     * @return this
     */
    public function setProductionMode($mode)
    {
        $this->productionMode = (bool)$mode;
        return $this;
    }

    /**
     * Load data
     */
    protected function loadDictonary()
    {
        if (!$this->loaded) {
            if (empty($this->files)) {
                throw new InvalidStateException('Language file(s) must be defined.');
            }

            $dictionaryTmp = $this->cache->load('dictionary-' . $this->lang);
            if ($this->productionMode && $dictionaryTmp) {
                $this->dictionary = $dictionaryTmp;
            } else {
                $files = [];
                if ($this->productionMode) {
                    foreach ($this->files AS $identifier => $dir) {
                        $path = "$dir/$this->lang.$identifier.mo";
                        if (file_exists($path)) {
                            $this->parseFile($path, $identifier);
                            $files[] = $path;
                        }
                    }
                    $this->cache->save('dictionary-' . $this->lang, $this->dictionary, array(
                        'expire' => time() * 60 * 60 * 2,
                        'files' => $files,
                        'tags' => array('dictionary-' . $this->lang)
                    ));
                } else {
                    foreach ($this->files AS $identifier => $dir) {
                        $path = "$dir/$this->lang.$identifier.po";
                        if (file_exists($path)) {
                            $this->parsePOFile($path, $identifier);
                            $files[] = $path;
                        }
                    }
                }
            }
            $this->loaded = TRUE;
        }
    }

    /**
     * Parse dictionary file
     * @param string $file file path
     * @param string
     */
    protected function parseFile($file, $identifier)
    {
        $f = @fopen($file, 'rb');
        if (@filesize($file) < 10) {
            throw new \InvalidArgumentException("'$file' is not a gettext file.");
        }

        $endian = FALSE;
        $read = function ($bytes) use ($f, $endian) {
            $data = fread($f, 4 * $bytes);
            return $endian === FALSE ? unpack('V' . $bytes, $data) : unpack('N' . $bytes, $data);
        };

        $input = $read(1);
        if (Strings::lower(substr(dechex($input[1]), -8)) == '950412de') {
            $endian = FALSE;
        } elseif (Strings::lower(substr(dechex($input[1]), -8)) == 'de120495') {
            $endian = TRUE;
        } else {
            throw new \InvalidArgumentException("'$file' is not a gettext file.");
        }

        $input = $read(1);

        $input = $read(1);
        $total = $input[1];

        $input = $read(1);
        $originalOffset = $input[1];

        $input = $read(1);
        $translationOffset = $input[1];

        fseek($f, $originalOffset);
        $orignalTmp = $read(2 * $total);
        fseek($f, $translationOffset);
        $translationTmp = $read(2 * $total);

        for ($i = 0; $i < $total; ++$i) {
            if ($orignalTmp[$i * 2 + 1] != 0) {
                fseek($f, $orignalTmp[$i * 2 + 2]);
                $original = @fread($f, $orignalTmp[$i * 2 + 1]);
            } else {
                $original = '';
            }

            if ($translationTmp[$i * 2 + 1] != 0) {
                fseek($f, $translationTmp[$i * 2 + 2]);
                $translation = fread($f, $translationTmp[$i * 2 + 1]);
                if ($original === '') {
                    $this->parseMetadata($translation, $identifier);
                    continue;
                }

                $original = explode("\0", $original);
                $translation = explode("\0", $translation);

                $key = isset($original[0]) ? $original[0] : $original;
                $this->dictionary[$key]['original'] = $original;
                $this->dictionary[$key]['translation'] = $translation;
                $this->dictionary[$key]['file'] = $identifier;

            }
        }
    }

    /**
     * @param string $path
     * @param string $identifier
     * @throws \Exception
     */
    private function parsePOFile(string $path,string $identifier){
        $fileHandler = new FileSystem($path);
        $poParser = new Parser($fileHandler);
        $catalog = $poParser->parse();
        foreach ($catalog->getEntries() as $entry) {
            $key = $entry->getMsgId();
            if($key === ""){
                continue;
            }
            if ($entry->getMsgStr() === null && $entry->getMsgStrPlurals() === null){
                continue;
            }
            $this->dictionary[$key]['original'] = $entry->getMsgId();
            $this->dictionary[$key]['translation'] = $entry->getMsgStr() !== null ? [$entry->getMsgStr()] : $entry->getMsgStrPlurals();
            $this->dictionary[$key]['file'] = $identifier;
        }
    }

    /**
     * Metadata parser
     * @param string $input
     * @param string
     */
    private function parseMetadata($input, $identifier)
    {
        $input = trim($input);

        $input = preg_split('/[\n,]+/', $input);
        foreach ($input AS $metadata) {
            $pattern = ': ';
            $tmp = preg_split("($pattern)", $metadata);
            $this->metadata[$identifier][trim($tmp[0])] = count($tmp) > 2 ? ltrim(strstr($metadata, $pattern), $pattern) : $tmp[1];
        }
    }

    /**
     * Translate given string
     * @param string $message
     * @param int $form plural form (positive number)
     * @return string
     */
    public function translate($message, $form = 1)
    {
        $this->loadDictonary();
        $files = array_keys($this->files);

        $message = (string)$message;
        $message_plural = NULL;
        if (is_array($form)) {
            $message_plural = current($form);
            $count = (int)end($form);
        } elseif (is_numeric($form)) {
            $count = (int)$form;
        } elseif (!is_int($form) || $form === NULL) {
            $count = 1;
        }

        if (!empty($message) && isset($this->dictionary[$message])) {
            $pluralForms = $this->metadataList['Plural-Forms'];
            if (isset($this->metadata[$files[0]]['Plural-Forms'])) {
                $pluralForms = $this->metadata[$files[0]]['Plural-Forms'];
            }
            $tmp = preg_replace('/([a-z]+)/', '$$1', "n=$count;" . $pluralForms);
            eval($tmp . '$message_plural = (int) $plural;');

            $message = $this->dictionary[$message]['translation'];

            if (!empty($message)) {
                $message = (is_array($message) && $message_plural !== null && isset($message[$message_plural])) ? $message[$message_plural] : $message;
            }

        } elseif(!$this->productionMode) {
            if (!$this->httpResponse->isSent() || $this->sessionStorage) {
                if (!isset($this->sessionStorage->newStrings[$this->lang])) {
                    $this->sessionStorage->newStrings[$this->lang] = [];
                }
                if (!isset($this->sessionStorage->newStrings['meta'])) {
                    $this->sessionStorage->newStrings['meta'] = [];
                }

                $this->sessionStorage->newStrings[$this->lang][$message] = empty($message_plural) ? array($message) : array($message, $message_plural);
                $call = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,2)[1];
                if(isset($call['file']) && isset($call['line'])) {
                    $meta = sprintf('%s:%s', $call['file'], $call['line']);
                    $this->sessionStorage->newStrings['meta'][$message][$meta] = $meta;
                }

                if ($this->scanToFile !== null){
                    $this->updatePOFile(self::SCAN_FILE_SECTION,$message,$message,'');
                }


            }

            if ($count > 1 && !empty($message_plural)) {
                $message = $message_plural;
            }
        }

        if (is_array($message)) {
            $message = current($message);
        }

        $args = func_get_args();
        if (count($args) > 1) {
            array_shift($args);
            if (is_array(current($args)) || current($args) === null) {
                array_shift($args);
            }

            if (count($args) == 1 && is_array(current($args))) {
                $args = current($args);
            }

            $message = str_replace(array('%label', '%name', '%value'), array('#label', '#name', '#value'), $message);
            if (count($args) > 0 && $args != null) {
                $message = vsprintf($message, $args);
            }
            $message = str_replace(array('#label', '#name', '#value'), array('%label', '%name', '%value'), $message);
        }

        return $message;
    }

    /**
     * Get count of plural forms
     * @return int
     */
    public function getVariantsCount()
    {
        $this->loadDictonary();
        $files = array_keys($this->files);
        $pluralForms = $this->metadataList['Plural-Forms'];
        if (isset($this->metadata[$files[0]]['Plural-Forms'])) {
            $pluralForms = $this->metadata[$files[0]]['Plural-Forms'];
        }

        return (int)substr($pluralForms, 9, 1);
    }

    /**
     * Get translations strings
     * @return array
     */
    public function getStrings($file = null)
    {
        $this->loadDictonary();

        $newStrings = [];
        $result = [];

        if (isset($this->sessionStorage->newStrings[$this->lang])) {
            foreach (array_keys($this->sessionStorage->newStrings[$this->lang]) as $original) {
                if (trim($original) != '') {
                    $newStrings[$original] = false;
                }
            }
        }

        foreach ($this->dictionary as $original => $data) {
            if (trim($original) != '') {
                if ($file && $data['file'] === $file) {
                    $result[$original] = $data['translation'];
                } else {
                    $result[$data['file']][$original] = $data['translation'];
                }
            }
        }

        if ($file) {
            return array_merge($newStrings, $result);
        } else {
            foreach ($this->getFiles() as $identifier => $path) {
                if (!isset($result[$identifier])) {
                    $result[$identifier] = [];
                }
            }

            return array('newStrings' => $newStrings) + $result;
        }
    }

    /**
     * Get loaded files
     * @return array
     */
    public function getFiles()
    {
        $this->loadDictonary();
        return $this->files;
    }

    /**
     * Set translation string(s)
     * @param string|array $message original string(s)
     * @param string|array $string translation string(s)
     * @param string
     */
    public function setTranslation($message, $string, $file)
    {
        $this->loadDictonary();

        if (isset($this->sessionStorage->newStrings[$this->lang]) && array_key_exists($message, $this->sessionStorage->newStrings[$this->lang])) {
            $message = $this->sessionStorage->newStrings[$this->lang][$message];
        }

        $key = is_array($message) ? $message[0] : $message;
        $this->dictionary[$key]['original'] = (array) $message;
        $this->dictionary[$key]['translation'] = (array) $string;
        $this->dictionary[$key]['file'] = $file;
    }

    /**
     * Save dictionary
     * @param string
     */
    public function save($file)
    {
        if (!$this->loaded) {
            throw new InvalidStateException('Nothing to save, translations are not loaded.');
        }

        if (!isset($this->files[$file])) {
            throw new \InvalidArgumentException("Gettext file identified as '$file' does not exist.");
        }

        $dir = $this->files[$file];
        $path = "$dir/$this->lang.$file";

        $this->buildMOFile("$path.mo", $file);

        if ($this->productionMode) {
            $this->cache->clean(array(
                'tags' => 'dictionary-' . $this->lang
            ));
        }
    }

    /**
     * Generate gettext metadata array
     * @param string $identifier
     * @return array
     */
    private function generateMetadata(string $identifier) : array
    {
        $result = [];
        $result[] = 'PO-Revision-Date: ' . date('Y-m-d H:iO');

        foreach ($this->metadataList AS $key => $default) {
            if (isset($this->metadata[$identifier][$key])) {
                $result[] = $key . ': ' . $this->metadata[$identifier][$key];
            } elseif ($default) {
                $result[] = $key . ': ' . $default;
            }
        }

        return $result;
    }


    /**
     * @param string $file
     * @param string $identifier
     * @param string $message
     * @param $translation
     * @throws \Exception
     */
    public function updatePOFile(string $file,string $identifier,string $message,$translation)
    {
        $dir = $this->files[$file];
        $path = "$dir/$this->lang.$file". ".po";
        // Parse a po file
        if (!file_exists($path)){
            $catalog = new CatalogArray();
            $header = new Header();
            $header->setHeaders($this->generateMetadata($identifier));
            $catalog->addHeaders($header);
            touch($path);
            $fileHandler = new FileSystem($path);
        } else {
            $fileHandler = new FileSystem($path);
            $poParser = new Parser($fileHandler);
            $catalog = $poParser->parse();
        }

        // Update entry
        $entry = $catalog->getEntry($message);
        if ($entry === null) {
            $entry = new Entry($message);
            if (isset($this->sessionStorage->newStrings['meta'][$message]) && is_array($this->sessionStorage->newStrings['meta'][$message])){
                $meta = $this->sessionStorage->newStrings['meta'][$message];
                unset($this->sessionStorage->newStrings['meta'][$message]);
            }
            $meta[] = 'Added from Tracy bar';
            $entry->setReference($meta);
        }

        if (!is_array($translation)) {
            $entry->setMsgStr($translation);
        } elseif (count($translation) < 2) {
            $entry->setMsgStr(current($translation));
        } else {
            $entry->setMsgStrPlurals($translation);
        }
        if(isset($this->sessionStorage->newStrings['meta'][$message])){
            if (is_array($this->sessionStorage->newStrings['meta'][$message])) {
                $entry->setReference($this->sessionStorage->newStrings['meta'][$message]);
            }
            unset($this->sessionStorage->newStrings['meta'][$message]);
        }

        $catalog->addEntry($entry);

        $compiler = new PoCompiler();
        $fileHandler->save($compiler->compile($catalog));
    }


    /**
     * Build gettext PO file
     * @param string $path
     * @param string $identifier
     */
    private function buildPOFile(string $path,string $identifier)
    {

        if (!file_exists($path)){
            $catalog = new CatalogArray();
            $catalog->addHeaders($this->generateMetadata($identifier));
            touch($path);
            $fileHandler = new FileSystem($path); //?
        } else {
            $fileHandler = new FileSystem($path);
            $poParser = new Parser($fileHandler);
            $catalog = $poParser->parse();
        }

        foreach ($this->dictionary as $message => $data) {
            if ($data['file'] !== $identifier) {
                continue;
            }
            // Update entry
            $entry = $catalog->getEntry($message);
            if ($entry === null) {
                $entry = new Entry($message);
                $entry->setReference(['Added from Tracy bar']);
            }

            if (!is_array($data['translation'])) {
                $entry->setMsgStr($data['translation']);
            } elseif (count($data['translation']) < 2) {
                $entry->setMsgStr(current($data['translation']));
            } else {
                $entry->setMsgStrPlurals($data['translation']);
            }

            $catalog->addEntry($entry);
        }

        if (isset($this->sessionStorage->newStrings[$this->lang])) {
            foreach ($this->sessionStorage->newStrings[$this->lang] as $message) {
                $entry = $catalog->getEntry(current($message));
                if ($entry === null) {
                    $entry = new Entry(current($message));
                    $entry->setReference(['Added from Tracy bar']);
                    $catalog->addEntry($entry);
                }
            }
        }
        $compiler = new PoCompiler();
        $fileHandler->save($compiler->compile($catalog));
    }

    /**
     * Build gettext MO file
     * @param string $file
     * @param string
     */
    private function buildMOFile($file, $identifier)
    {
        $dictionary = array_filter($this->dictionary, function ($data) use ($identifier) {
            return $data['file'] === $identifier;
        });

        ksort($dictionary);

        $metadata = implode("\n", $this->generateMetadata($identifier));

        $items = count($dictionary) + 1;
        $ids = Strings::chr(0x00);
        $strings = $metadata . Strings::chr(0x00);
        $idsOffsets = array(0, 28 + $items * 16);
        $stringsOffsets = array(array(0, strlen($metadata)));

        foreach ($dictionary AS $key => $value) {
            $id = $key;
            if (is_array($value['original']) && count($value['original']) > 1) {
                $id .= Strings::chr(0x00) . end($value['original']);
            }

            $string = implode(Strings::chr(0x00), $value['translation']);
            $idsOffsets[] = strlen($id);
            $idsOffsets[] = strlen($ids) + 28 + $items * 16;
            $stringsOffsets[] = array(strlen($strings), strlen($string));
            $ids .= $id . Strings::chr(0x00);
            $strings .= $string . Strings::chr(0x00);
        }

        $valuesOffsets = [];
        foreach ($stringsOffsets AS $offset) {
            list ($all, $one) = $offset;
            $valuesOffsets[] = $one;
            $valuesOffsets[] = $all + strlen($ids) + 28 + $items * 16;
        }
        $offsets = array_merge($idsOffsets, $valuesOffsets);

        $mo = pack('Iiiiiii', 0x950412de, 0, $items, 28, 28 + $items * 8, 0, 28 + $items * 16);
        foreach ($offsets AS $offset) {
            $mo .= pack('i', $offset);
        }

        file_put_contents($file, $mo . $ids . $strings);
    }

    public function detectLanguage()
    {
        $lang = null;
        if (array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER)) {
            $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        }

        if (!$lang) {
            $lang = $this->getLang();
        }
        return $lang;
    }
}