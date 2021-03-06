<?php

namespace Platformsh\Cli\Local\Toolstack;

use Platformsh\Cli\CliConfig;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Helper\ShellHelperInterface;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ToolstackBase implements ToolstackInterface
{

    /**
     * Files from the app root to ignore during install.
     *
     * @var string[]
     */
    protected $ignoredFiles = [];

    /**
     * Special destinations for installation.
     *
     * @var array
     *   An array of filenames in the app root, mapped to destinations. The
     *   destinations are filenames supporting the replacements:
     *     "{webroot}" - see getWebRoot() (usually /app/public on Platform.sh)
     *     "{approot}" - the $buildDir (usually /app on Platform.sh)
     */
    protected $specialDestinations = [];

    /** @var LocalApplication */
    protected $app;

    /** @var array */
    protected $settings = [];

    /** @var string  */
    protected $buildDir;

    /** @var bool */
    protected $copy = false;

    /** @var OutputInterface */
    protected $output;

    /** @var FilesystemHelper */
    protected $fsHelper;

    /** @var GitHelper */
    protected $gitHelper;

    /** @var ShellHelperInterface */
    protected $shellHelper;

    /** @var CliConfig */
    protected $config;

    /** @var string */
    protected $appRoot;

    /** @var string */
    private $documentRoot;

    /**
     * Whether all app files have just been symlinked or copied to the build.
     *
     * @var bool
     */
    private $buildInPlace = false;

    /**
     * @param object               $fsHelper
     * @param ShellHelperInterface $shellHelper
     * @param object               $gitHelper
     */
    public function __construct($fsHelper = null, ShellHelperInterface $shellHelper = null, $gitHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
        $this->fsHelper = $fsHelper ?: new FilesystemHelper($this->shellHelper);
        $this->gitHelper = $gitHelper ?: new GitHelper($this->shellHelper);

        $this->specialDestinations = [
            "favicon.ico" => "{webroot}",
            "robots.txt" => "{webroot}",
        ];

        // Platform.sh has '.platform.app.yaml', but we need to be stricter.
        $this->ignoredFiles = ['.*', ];
    }

    /**
     * @inheritdoc
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        $this->shellHelper->setOutput($output);
    }

    /**
     * @inheritdoc
     */
    public function addIgnoredFiles(array $ignoredFiles)
    {
        $this->ignoredFiles = array_merge($this->ignoredFiles, $ignoredFiles);
    }

    /**
     * @inheritdoc
     */
    public function prepare($buildDir, LocalApplication $app, CliConfig $config, array $settings = [])
    {
        $this->app = $app;
        $this->appRoot = $app->getRoot();
        $this->documentRoot = $app->getDocumentRoot();
        $this->settings = $settings;
        $this->config = $config;

        if ($this->config->get('local.copy_on_windows')) {
            $this->fsHelper->setCopyOnWindows(true);
        }
        $this->ignoredFiles[] = $this->config->get('local.web_root');

        $this->buildDir = $buildDir;

        $this->copy = !empty($settings['copy']);
        $this->fsHelper->setRelativeLinks(empty($settings['absoluteLinks']));
    }

    /**
     * Process the defined special destinations.
     */
    protected function processSpecialDestinations()
    {
        foreach ($this->specialDestinations as $sourcePattern => $relDestination) {
            $matched = glob($this->appRoot . '/' . $sourcePattern, GLOB_NOSORT);
            if (!$matched) {
                continue;
            }
            if ($relDestination === '{webroot}' && $this->buildInPlace) {
                continue;
            }

            // On Platform.sh these replacements would be a bit different.
            $absDestination = str_replace(
                ['{webroot}', '{approot}'],
                [$this->getWebRoot(), $this->buildDir],
                $relDestination
            );

            foreach ($matched as $source) {
                // Ignore the source if it's in ignoredFiles.
                $relSource = str_replace($this->appRoot . '/', '', $source);
                if (in_array($relSource, $this->ignoredFiles)) {
                    continue;
                }
                $destination = $absDestination;
                // Do not overwrite directories with files.
                if (!is_dir($source) && is_dir($destination)) {
                    $destination = $destination . '/' . basename($source);
                }
                // Ignore if source and destination are the same.
                if ($destination === $source) {
                    continue;
                }
                if ($this->copy) {
                    $this->output->writeln("Copying $relSource to $relDestination");
                }
                else {
                    $this->output->writeln("Symlinking $relSource to $relDestination");
                }
                // Delete existing files, emitting a warning.
                if (file_exists($destination)) {
                    $this->output->writeln(
                        sprintf(
                            "Overriding existing path '%s' in destination",
                            str_replace($this->buildDir . '/', '', $destination)
                        )
                    );
                    $this->fsHelper->remove($destination);
                }
                if ($this->copy) {
                    $this->fsHelper->copy($source, $destination);
                }
                else {
                    $this->fsHelper->symlink($source, $destination);
                }
            }
        }
    }

    /**
     * Get the directory containing files shared between builds.
     *
     * This will be 'shared' for a single-application project, or
     * 'shared/<appName>' when there are multiple applications.
     *
     * @return string|false
     */
    protected function getSharedDir()
    {
        if (empty($this->settings['sourceDir'])) {
            return false;
        }
        $shared = $this->settings['sourceDir'] . '/' . $this->config->get('local.shared_dir');
        if (!empty($this->settings['multiApp'])) {
            $shared .= '/' . preg_replace('/[^a-z0-9\-_]+/i', '-', $this->app->getName());
        }
        if (!is_dir($shared)) {
            mkdir($shared, 0755, true);
        }

        return $shared;
    }

    /**
     * @inheritdoc
     */
    public function getWebRoot()
    {
        return $this->buildDir . '/' . $this->documentRoot;
    }

    /**
     * @return string
     */
    public function getAppDir()
    {
        return $this->buildDir;
    }

    /**
     * Copy, or symlink, files from the app root to the build directory.
     *
     * @return string
     *   The absolute path to the build directory where files have been copied.
     */
    protected function copyToBuildDir()
    {
        $this->buildInPlace = true;
        $buildDir = $this->buildDir;
        if ($this->app->shouldMoveToRoot()) {
            $buildDir .= '/' . $this->documentRoot;
        }
        if ($this->copy) {
            $this->fsHelper->copyAll($this->appRoot, $buildDir, $this->ignoredFiles, true);
        }
        else {
            $this->fsHelper->symlink($this->appRoot, $buildDir);
        }

        return $buildDir;
    }

    /**
     * @inheritdoc
     */
    public function install()
    {
        // Override to define install steps.
    }

    /**
     * @inheritdoc
     */
    public function getKey()
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function canArchive()
    {
        return !$this->buildInPlace || $this->copy;
    }

    /**
     * Create a default .gitignore file for the app.
     *
     * @param string $source The path to a default .gitignore file, relative to
     *                       the 'resources' directory.
     */
    protected function copyGitIgnore($source)
    {
        $source = CLI_ROOT . '/resources/' . $source;
        if (!file_exists($source) || empty($this->settings['sourceDir']) || !!$this->gitHelper->isRepository($this->settings['sourceDir'])) {
            return;
        }
        $appGitIgnore = $this->appRoot . '/.gitignore';
        if (!file_exists($appGitIgnore) && !file_exists($this->settings['sourceDir'] . '/.gitignore')) {
            $this->output->writeln("Creating a .gitignore file");
            copy($source, $appGitIgnore);
        }
    }

}
