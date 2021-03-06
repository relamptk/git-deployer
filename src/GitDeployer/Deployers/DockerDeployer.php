<?php
namespace GitDeployer\Deployers;

class DockerDeployer extends BaseDeployer {   

    //////////////////////////////////////////////////////////////////
    // Service functions
    //////////////////////////////////////////////////////////////////

    /**
     * Gets the help text that shows when "help deploy docker" is
     * executed by the user
     * @return strinf
     */
    public static function getHelp() {
        return <<<HELP
 The <info>docker</info> deployer allows you to deploy containers to Docker / Docker Swarm instances.
 It will use the <comment>Dockerfile</comment> in your repository to create an image to start the
 container from.

 For the <info>docker</info> deployer, we currently have the following configuration options:

 <comment>"host"</comment>:        (string) This variable overrides the DOCKER_HOST environment variable. If 
                you do not specify it, we will use the value from DOCKER_HOST instead.

                Valid formats are unix sockets, like <comment>unix:///var/run/docker.sock</comment>, and tcp 
                sockets, like <comment>tcp://127.0.0.1:2375</comment>.

 <comment>"ssl"</comment>:         (boolean) Whether to enable SSL for the connection (default false)

 <comment>"restart"</comment>:     (string) This variable controls whether Docker will restart the container
                and under what condition. Possible values are: no (default), on-failure[:max-retries], 
                always, unless-stopped.

 <comment>"ssh"</comment>:         (object) This variable allows you to specify a SSH-tunnel that will be created
                and used to connect to the DOCKER_HOST variable. Only supported with tcp://-style URLs.
    
                You will need to specify the following sub-parameters:

                <comment>"tunnel"</comment>:     Set to true to enable SSH-tunneling (required).
                <comment>"user"</comment>:       The user for the SSH connection on the remote host (optional, will use "root" as default).
                <comment>"host"</comment>:       The remote host for the SSH connection (required).
                <comment>"port"</comment>:       The remote host port for the SSH connection (optional, will use 22 as default).
                <comment>"key"</comment>:        The SSH private key file for authentication to the remote host (required)
                <comment>"password"</comment>:   The password for the SSH private key file (optional, you will be asked for a password if needed)

 <comment>"ports"</comment>:       (array) Specifies the ports you want Docker to expose. If supports the complete Docker
                port description syntax, which is: [[hostIp:][hostPort]:]port[/protocol]. Examples:

                80
                80/tcp
                8080:80
                ...

 <comment>"environment"</comment>: (object) Specifies the environment variables passed to Docker. Of type key => value, examples:

                "dbtype": "mysql",
                "dbuser": "user",
                "dbpassword": "%dbpassword%"    (placeholders supported)

 <comment>"volumes"</comment>:     (array) Specifies the volumes that should be attached to the container when started. Examples:

                "container_path"                (To create a new volume for the container)
                "host_path:container_path"      (To bind-mount a host path into the container)
                "host_path:container_path:ro"   (To make the bind-mount read-only inside the container)


HELP;

    }

    /**
     * Uses Docker to deploy the given project to a live server
     * @param  \GitDeployer\Objects\Project $project The project to deploy
     * @param  string                       $gitpath The path to the checked-out project
     * @param  array                        $config  The configuration options to pass to this deployer
     * @return mixed
     */
    public function deploy(\GitDeployer\Objects\Project $project, $gitpath, $config) {

        $useTunnel = false;

        // -> Connect to the docker daemon on a tcp or unix socket
        if (!isset($config['host']) || strlen($config['host']) < 1) $config['host'] = getenv('DOCKER_HOST');
        if (strlen($config['host']) < 1) throw new \Exception('Neither the "host" parameter was specified in the .deployer file nor is the DOCKER_HOST environment variable set!');
        
        if (stristr($config['host'], 'tcp://')) {
            // Setting the docker host to tcp:// may enable usage of the SSH tunnel functionality
            if (isset($config['ssh']) && is_array($config['ssh'])) {
                if (isset($config['ssh']['tunnel']) && $config['ssh']['tunnel'] == true) {
                    $useProc = false;
                    $useTunnel = true;

                    parent::showMessage('DOCKER', 'Connecting to Docker daemon via SSH...', $this->output);

                    // Check if the ssh binary is executable, else bail out
                    // since we can't open a tunnel without it
                    if (!$this->commandExists('ssh')) {
                        throw new \Exception('SSH client not found: Please make sure the "ssh" command is available, and in your $PATH!');
                    } else {
                        if (!isset($config['ssh']['host']) || strlen($config['ssh']['host']) < 1) throw new \Exception('Please specify at least a SSH host in your .deployerfile to connect to!');                        
                        if (!isset($config['ssh']['user']) || strlen($config['ssh']['user']) < 1) $config['ssh']['user'] = "root";
                        $config['ssh']['port'] = isset($config['ssh']['port']) && strlen($config['ssh']['port']) > 0 ? $config['ssh']['port'] : 22;

                        if (!isset($config['ssh']['privatekey']) || strlen($config['ssh']['privatekey']) < 1) throw new \Exception('Please correctly specify your SSH private key in the .deployerfile!');                        

                        // -> Open tunnel via SSH command
                        $randport = rand(60000, 65000);
                        $remotedesc = str_replace('tcp://', '', $config['host']);

                        $cmdstring = 'ssh -N -i ' . escapeshellarg($config['ssh']['privatekey']) . ' -L ' . $randport . ':' . $remotedesc . ' -p ' . $config['ssh']['port'] . ' ' . $config['ssh']['user'] . '@' . $config['ssh']['host'];                        

                        if (isset($config['ssh']['password']) && strlen($config['ssh']['password']) > 1) {
                            if (!extension_loaded('expect')) {
                                throw new \Exception('Expect extension not found: Please make sure the PHP expect extension is available in your PHP installation!');
                            }

                            $stream = fopen('expect://' . $cmdstring, 'r');

                            $cases = array (
                                array ('Enter passphrase', PASSWORD)
                            );

                            ini_set("expect.timeout", 30);

                            switch (expect_expectl ($stream, $cases)) {
                                case PASSWORD:
                                    fwrite ($stream, $config['ssh']['password'] . "\n");

                                    // Wait for tunnel port to be available
                                    while(true) {
                                        $socket = @fsockopen('127.0.0.1', $randport, $errno, $errstr, 5);
                                           
                                        if ($socket) {
                                            fclose($socket);
                                            break;
                                        }
                                    }                                    

                                    break;
                                default:
                                    throw new \Exception('Unable to connect to the remote SSH host! Invalid string received: Expected passphrase prompt.');                                    
                            }
                        } else {
                            $stream = proc_open('exec ' . $cmdstring, array(), $pipes);   
                            $useProc = true;

                            // Wait for tunnel port to be available
                            while(true) {
                                $socket = @fsockopen('127.0.0.1', $randport, $errno, $errstr, 5);

                                if ($socket) {
                                    fclose($socket);
                                    break;
                                }
                            }  
                        }
                    }
                }
            }
        }

        $client = new \Docker\DockerClient(array(
            'remote_socket' => 'tcp://127.0.0.1:' . $randport,
            'ssl' => ( isset($config['ssl']) && $config['ssl'] == true ? true : false )
        ));

        $docker = new \Docker\Docker($client);

        // -> Build the docker image if a Dockerfile is present
        if (!file_exists($gitpath . '/Dockerfile')) {
            throw new \Exception('No Dockerfile found - aborting build!');
        }

        parent::showMessage('DOCKER', 'Building image (no-cache)...', $this->output);
        parent::showMessage('DOCKER', 'Uploading context...', $this->output);

        $context = new \Docker\Context\Context($gitpath);

        $imageManager = $docker->getImageManager();
        $buildStream = $imageManager->build($context->toStream(), array(
            't' => 'git-deployer/' . $project->name()
        ), \Docker\Manager\ContainerManager::FETCH_STREAM);

        $buildStream->onFrame(function (\Docker\API\Model\BuildInfo $buildInfo) {
            parent::showMessage('BUILD', $buildInfo->getStream(), $this->output);
        });

        $buildStream->wait();

        // -> Stop and remove the old container with the same name, sicne we're going
        // to replace the app here with the newly built container
        parent::showMessage('DOCKER', 'Getting running containers...', $this->output);

        $containersOnHost = $docker->getContainerManager()->findAll();

        if (count($containersOnHost) > 0) {
            // We check for a container with the same name as the one we are going to deploy
            foreach ($containersOnHost as $key => $container) {
                $containerFound = false;

                // Search by name
                foreach ($container->getNames() as $name) {
                    $cleanName = $this->cleanName($project->name());
                    preg_match('#\/.*\/(.*)#', $name, $matches);

                    if ($cleanName == $matches[1]) {
                        $containerFound = true;
                    }
                }

                // Search by image
                if ($container->getImage() == 'git-deployer/' . $project->name()) {
                    $containerFound = true;   
                }

                if ($containerFound) {
                    parent::showMessage('DOCKER', 'Stopping old container ' . $container->getId() .  '...', $this->output);

                    $docker->getContainerManager()->stop($container->getId());
                    $docker->getContainerManager()->remove($container->getId());
                }
            }
        }

        // -> Start the container up if we have built sucessfully
        parent::showMessage('DOCKER', 'Starting new container...', $this->output);

        $hostConfig = new \Docker\API\Model\HostConfig();

        $containerConfig = new \Docker\API\Model\ContainerConfig();        
        $containerConfig->setNames(array('git-deployer/' . $this->cleanName($project->name())));
        $containerConfig->setImage('git-deployer/' . $project->name());

        // Add environment from the config file, if any
        $envArray = array();

        if (isset($config['environment'])) {
            foreach ($config['environment'] as $key => $value) {
                $envArray[] = $key . '=' . $value;
            }
        }

        $containerConfig->setEnv($envArray);

        // Add exposed ports from the config file, if any
        if (isset($config['ports']) && is_array($config['ports']) && count($config['ports']) > 0) {
            $exposedPorts = new \ArrayObject();
            $mapPorts = new \ArrayObject();

            foreach ($config['ports'] as $portdesc) {
                $portspec = $this->parsePortSpecification($portdesc);
                
                // Exposed port
                $exposedPort = $portspec['port'] . (strlen($portspec['protocol']) > 0 ? '/' . $portspec['protocol'] : '/tcp' );

                $exposedPorts[$exposedPort] = new \stdClass();

                // Host port binding
                $hostPortBinding = new \Docker\API\Model\PortBinding();
                $mapPorts[$exposedPort] = array($hostPortBinding);
            }

            $containerConfig->setExposedPorts($exposedPorts);
            $hostConfig->setPortBindings($mapPorts);
        }

        // Add restart policy
        if (isset($config['restart']) && strlen($config['restart']) > 0) {
            $policy = $this->parseRestartPolicy($config['restart']);

            $restartPolicy = new \Docker\API\Model\RestartPolicy();
            $restartPolicy->setName($policy['Name']);
            if (isset($policy['MaximumRetryCount'])) $restartPolicy->setMaximumRetryCount($policy['MaximumRetryCount']);

            $hostConfig->setRestartPolicy($restartPolicy);
        }

        // Add binds
        if (isset($config['volumes']) && is_array($config['volumes']) && count($config['volumes']) > 0) {
            $binds = new \ArrayObject();

            foreach ($config['volumes'] as $volume) {
                $binds[] = $volume;
            }

            $hostConfig->setBinds($binds);
        }

        $containerConfig->setHostConfig($hostConfig);
        $containerCreateResult = $docker->getContainerManager()->create($containerConfig, array('name' => $this->cleanName($project->name())));
        
        if ($containerCreateResult->getId()) {
            $docker->getContainerManager()->start($containerCreateResult->getId());
        }

        // -> Clean up and close the SSH tunnel
        if ($useTunnel) {
            if ($useProc) {
                proc_terminate($stream, 9);
                proc_close($stream);
            } else {
                fclose($stream);
            }
        }

        return array(
            true,
            'No trace'
        );

    }

    /**
     * Determines if a command exists on the current environment
     *
     * @param  string $command The command to check
     * @return bool
     */
    private function commandExists($command) {
        
        $whereIsCommand = (PHP_OS == 'WINNT') ? 'where' : 'which';

        $process = proc_open($whereIsCommand . " " . $command, array(
          0 => array("pipe", "r"), //STDIN
          1 => array("pipe", "w"), //STDOUT
          2 => array("pipe", "w"), //STDERR
        ), $pipes);

        if ($process !== false) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            return $stdout != '';
        }

        return false;

    }

    /**
     * Parses a Docker restart policy and returns an array 
     * with the policy options
     * @param  string $policy The Docker restart policy
     * @return array
     */
    private function parseRestartPolicy($policy) {

        $policyExplode = explode(':', $policy);
        
        $policy = array(
            'Name' => $policyExplode[0]
        );

        if (isset($policyExplode[1]) && is_numeric($policyExplode[1]) && $policyExplode[0] == 'on-failure') {
            $policy['MaximumRetryCount'] = $policyExplode[1];
        }

        return $policy;

    }

    /**
     * Parses a Docker port specification and returns the port data
     * @param  string $portSpecification The Docker port specification
     * @return array
     */
    private function parsePortSpecification($portSpecification) {
        
        if (!preg_match('/(?:(?<hostIp>[0-9\.]{7,15}):)?(?:(?<hostPort>\d{1,5}|):)?(?<port>\d{1,5})(?:\/(?<protocol>\w+))?/', $portSpecification, $matches)) {
            throw new \Exception('Invalid port specification "' . $portSpecification . '"');
        }

        $parsed = [];
        
        foreach (['hostIp', 'hostPort', 'port', 'protocol'] as $key) {
            if (array_key_exists($key, $matches)) {
                $parsed[$key] = strlen($matches[$key]) > 0
                    ? (is_numeric($matches[$key])
                        ? (integer) $matches[$key]
                        : $matches[$key])
                    : null;
            } else {
                $parsed[$key] = null;
            }
        }
        
        return $parsed;

    }

    /**
     * Makes sure the project name is Docker compatible
     * @param  string $projectname The Git-Deployer project name
     * @return string
     */
    private function cleanName($projectName) {

        // Special handling for dot '.', as it
        // should remain constant in the string always
        $projectName = str_replace('.', '_', $projectName);

        // Remove any characters that don't match
        // [a-zA-Z0-9_-]
        $projectName = preg_replace('#([^a-zA-Z0-9_\-]*)#', '', $projectName);

        return $projectName;

    }

}
