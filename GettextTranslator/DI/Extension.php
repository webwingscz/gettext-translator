<?php
namespace Webwings\Gettext\Translator\DI;

use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;

if (!class_exists('Nette\DI\CompilerExtension'))
{
    class_alias('Nette\Config\CompilerExtension', 'Nette\DI\CompilerExtension');
}
class Extension extends CompilerExtension
{
    /** @var array */
    private $defaults = array(
        'lang' => 'en',
        'files' => array(),
        'layout' => 'horizontal',
        'height' => 450
    );

    public function loadConfiguration()
    {
        $config = $this->getConfig($this->defaults);
        $builder = $this->getContainerBuilder();
        $translator = $builder->addDefinition($this->prefix('translator'));
        $translator->setClass('Webwings\Gettext\Translator\Gettext', array('@session', '@cacheStorage', '@httpResponse'));
        $translator->addSetup('setLang', array($config['lang']));
        $translator->addSetup('setProductionMode', array($builder->expand('%productionMode%')));
        foreach ($config['files'] AS $id => $file)
        {
            $translator->addSetup('addFile', array($file, $id));
        }
        $translator->addSetup('Webwings\Gettext\Translator\Panel::register', array('@application', '@self', '@session', '@httpRequest', $config['layout'], $config['height']));
    }
}