<?php

namespace Composer\Repository;

use Composer\Downloader\TransportException;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VcsRepository extends ArrayRepository
{
    protected $url;
    protected $packageName;
    protected $debug;
    protected $io;
    protected $versionParser;
    protected $type;

    public function __construct(array $config, IOInterface $io, array $drivers = null)
    {
        $this->drivers = $drivers ?: array(
            'github'        => 'Composer\Repository\Vcs\GitHubDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git'           => 'Composer\Repository\Vcs\GitDriver',
            'hg-bitbucket'  => 'Composer\Repository\Vcs\HgBitbucketDriver',
            'hg'            => 'Composer\Repository\Vcs\HgDriver',
            'svn'           => 'Composer\Repository\Vcs\SvnDriver',
        );

        $this->url = $config['url'];
        $this->io = $io;
        $this->type = $config['type'];
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    public function getDriver()
    {
        if (isset($this->drivers[$this->type])) {
            $class = $this->drivers[$this->type];
            $driver = new $class($this->url, $this->io);
            $driver->initialize();
            return $driver;
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->url)) {
                $driver = new $driver($this->url, $this->io);
                $driver->initialize();
                return $driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->url, true)) {
                $driver = new $driver($this->url, $this->io);
                $driver->initialize();
                return $driver;
            }
        }
    }

    protected function initialize()
    {
        parent::initialize();

        $debug = $this->debug;

        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $this->versionParser = new VersionParser;
        $loader = new ArrayLoader();

        try {
            if ($driver->hasComposerFile($driver->getRootIdentifier())) {
                $data = $driver->getComposerInformation($driver->getRootIdentifier());
                $this->packageName = !empty($data['name']) ? $data['name'] : null;
            }
        } catch (\Exception $e) {
            if ($debug) {
                $this->io->write('Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage());
            }
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $msg = 'Get composer info for <info>' . $this->packageName . '</info> (<comment>' . $tag . '</comment>)';
            if ($debug) {
                $this->io->write($msg);
            } else {
                $this->io->overwrite($msg, false);
            }

            if (!$parsedTag = $this->validateTag($tag)) {
                if ($debug) {
                    $this->io->write('Skipped tag '.$tag.', invalid tag name');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($debug) {
                        $this->io->write('Skipped tag '.$tag.', no composer file');
                    }
                    continue;
                }
            } catch (\Exception $e) {
                if ($debug) {
                    $this->io->write('Skipped tag '.$tag.', '.$e->getMessage());
                }
                continue;
            }

            // manually versioned package
            if (isset($data['version'])) {
                $data['version_normalized'] = $this->versionParser->normalize($data['version']);
            } else {
                // auto-versionned package, read value from tag
                $data['version'] = $tag;
                $data['version_normalized'] = $parsedTag;
            }

            // make sure tag packages have no -dev flag
            $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
            $data['version_normalized'] = preg_replace('{(^dev-|[.-]?dev$)}i', '', $data['version_normalized']);

            // broken package, version doesn't match tag
            if ($data['version_normalized'] !== $parsedTag) {
                if ($debug) {
                    $this->io->write('Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json');
                }
                continue;
            }

            if ($debug) {
                $this->io->write('Importing tag '.$tag.' ('.$data['version_normalized'].')');
            }

            $this->addPackage($loader->load($this->preProcess($driver, $data, $identifier)));
        }

        $this->io->overwrite('', false);

        foreach ($driver->getBranches() as $branch => $identifier) {
            $msg = 'Get composer info for <info>' . $this->packageName . '</info> (<comment>' . $branch . '</comment>)';
            if ($debug) {
                $this->io->write($msg);
            } else {
                $this->io->overwrite($msg, false);
            }

            if (!$parsedBranch = $this->validateBranch($branch)) {
                if ($debug) {
                    $this->io->write('Skipped branch '.$branch.', invalid name');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($debug) {
                        $this->io->write('Skipped branch '.$branch.', no composer file');
                    }
                    continue;
                }
            } catch (TransportException $e) {
                if ($debug) {
                    $this->io->write('Skipped branch '.$branch.', no composer file was found');
                }
                continue;
            } catch (\Exception $e) {
                $this->io->write('Skipped branch '.$branch.', '.$e->getMessage());
                continue;
            }

            // branches are always auto-versionned, read value from branch name
            $data['version'] = $branch;
            $data['version_normalized'] = $parsedBranch;

            // make sure branch packages have a dev flag
            if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
                $data['version'] = 'dev-' . $data['version'];
            } else {
                $data['version'] = $data['version'] . '-dev';
            }

            if ($debug) {
                $this->io->write('Importing branch '.$branch.' ('.$data['version_normalized'].')');
            }

            $this->addPackage($loader->load($this->preProcess($driver, $data, $identifier)));
        }

        $this->io->overwrite('', false);
    }

    private function preProcess(VcsDriverInterface $driver, array $data, $identifier)
    {
        // keep the name of the main identifier for all packages
        $data['name'] = $this->packageName ?: $data['name'];

        if (!isset($data['dist'])) {
            $data['dist'] = $driver->getDist($identifier);
        }
        if (!isset($data['source'])) {
            $data['source'] = $driver->getSource($identifier);
        }

        return $data;
    }

    private function validateBranch($branch)
    {
        try {
            return $this->versionParser->normalizeBranch($branch);
        } catch (\Exception $e) {
        }

        return false;
    }

    private function validateTag($version)
    {
        try {
            return $this->versionParser->normalize($version);
        } catch (\Exception $e) {
        }

        return false;
    }
}
