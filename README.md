**DEPRECATION WARNING: AFTER A YEAR OF USING GIT-DEPLOYER, I SWITCHED TO A CONTINOUS INTEGRATION/BUILD SYSTEM VIA DOCKER/NOMAD/DRONE. AS SUCH,
THIS PROJECT IS NO LONGER MAINTAINED.**

[![License](https://img.shields.io/github/license/beniwtv/git-deployer.svg)](https://img.shields.io/github/license/beniwtv/git-deployer.svg)

Git-Deployer
============

Welcome to Git-Deployer! Git-Deployer is a tool which you can use to manage
your deployments from Git repositories.

This document contains information on how to download, install, and start
using Git-Deployer.

1) Installing Git-Deployer
--------------------------

To install Git-Deployer, you can download a PHAR-archive, and put it
somewhere in your $PATH, for example:

```
sudo curl -L -o /usr/bin/git-deployer https://github.com/relamptk/git-deployer/releases/download/0.1.2/git-deployer.phar
sudo curl -L -o /usr/bin/git-deployer.pubkey https://github.com/relamptk/git-deployer/releases/download/0.1.2/git-deployer.phar.pubkey
sudo chmod +x /usr/bin/git-deployer
```

2) Using Git-Deployer
---------------------

First, you will need to log-in to a Git service, like GitLab or GitHub. To
know which services are available to you currently, use: 

```
git-deployer help login
```

This will list all services that are currently available in git-deployer. When
you have chosen a service, log in to it with the command:

```
git-deployer login <service>
```

The service may ask you a few questions, like the log-in user and password.
After you have logged in, execute the config command, which will guide you through
the configuration for the rest of Git-Deployer:

```
git-deployer config
```

After you have sucessfully configured Git-Deployer, you can check the status of your
deployments with the status command:

```
git-deployer status
```

To obtain a little bit more information about a Git project, use the info command:

```
git-deployer info <projectname>
```

You can also delete all information from Git-Deployer if you use the logout command:

```
git-deployer logout
```

3) Deployment with Git-Deployer
--------------------------------

Sometimes, it is useful to see the Git history before deploying. You can show 
the history of your Git repository with the history command:

```
git-deployer history <projectname>
```

To be able to deploy a Git repository with Git-Deployer, you must first add the 
project so that Git-Deployer is made aware of the new project:

```
git-deployer add <projectname>
```

You can also remove an added Project with the remove command:

```
git-deployer remove <projectname>
```

Next step is to create a .deployerfile in your repository, which will tell Git-Deployer
how to deploy your project. For that, execute the init command in the root of your 
Git repository:

```
git-deployer init
```

Once you have your .deployerfile, make sure to configure it according to your needs.
An explanation of the configuration of this file can be found by executing:

```
git-deployer help init
```

Once you are ready, start the deployment with the deploy command, for example:

```
git-deployer deploy <projectname> tag:v1.0.0
```

Optionally, you can pass a specific configuration section of your .deployerfile:

```
git-deployer deploy <projectname> tag:v1.0.0 -c <configuration>
```

Enjoy!

4) About "Builders and "Deployers"
----------------------------------

**NOTE**: This is new as of Git-Deployer 1.0.0. Older .deployerfiles will need to 
be upgraded to the new format - don't worry, it's largely the same though.

Builders and deployers are plugins for Git-Deployer that allow you to modify how 
a project is built and deployed to a server. The builder/deployer to use can be 
set ona project by project basis, in the .deployerfile.

To check which builders/deployers have been integrated into your build of 
Git-Deployer, execute:

```
git-deployer help init
```

To get help about a specific builder, execute:

```
git-deployer help build <builder>
```

To get help about a specific deployer, execute:

```
git-deployer help deploy <deployer>
```

5) More!
--------

See git-deployer -h for more commands and help!
