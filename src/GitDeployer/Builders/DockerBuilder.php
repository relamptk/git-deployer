<?php
namespace GitDeployer\Builders;

class DockerBuilder extends BaseBuilder {   

    //////////////////////////////////////////////////////////////////
    // Service functions
    //////////////////////////////////////////////////////////////////

    /**
     * Gets the help text that shows when "help build docker" is
     * executed by the user
     * @return string
     */
    public static function getHelp() {
        return <<<HELP
 The <info>docker</info> builder allows you to build Docker images, and push it to any registry you need.
 It will use the <comment>Dockerfile</comment> in your repository to create this image.

 For the <info>docker</info> builder, we currently have the following configuration options:

 <comment>"host"</comment>:        (string) This variable overrides the DOCKER_HOST environment variable. If 
                you do not specify it, we will use the value from DOCKER_HOST instead.

                Valid formats are unix sockets, like <comment>unix:///var/run/docker.sock</comment>, and tcp 
                sockets, like <comment>tcp://127.0.0.1:2375</comment>.

 <comment>"ssl"</comment>:         (boolean) Whether to enable SSL for the connection (default false)

 <comment>"ssh"</comment>:         (object) This variable allows you to specify a SSH-tunnel that will be created
                and used to connect to the DOCKER_HOST variable. Only supported with tcp://-style URLs.
    
                You will need to specify the following sub-parameters:

                <comment>"tunnel"</comment>:     Set to true to enable SSH-tunneling (required).
                <comment>"user"</comment>:       The user for the SSH connection on the remote host (optional, will use "root" as default).
                <comment>"host"</comment>:       The remote host for the SSH connection (required).
                <comment>"port"</comment>:       The remote host port for the SSH connection (optional, will use 22 as default).
                <comment>"key"</comment>:        The SSH private key file for authentication to the remote host (required)
                <comment>"password"</comment>:   The password for the SSH private key file (optional, you will be asked for a password if needed)

HELP;

    }

    /**
     * Uses Docker to build the given project as a Docker image
     * @param  \GitDeployer\Objects\Project $project The project to build
     * @param  string                       $gitpath The path to the checked-out project
     * @param  array                        $config  The configuration options to pass to this builder
     * @return mixed
     */
    public function build(\GitDeployer\Objects\Project $project, $gitpath, $config) {

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

        // Push image to registry, if it's not the local one
        

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

}