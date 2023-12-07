# Chronos Control

Chronos is a system for the automation of the entire evaluation workflow including the set-up of the evaluation, its execution, and the subsequent analysis of the results. It allows to evaluate a wide range of applications from simple databases to complex polystores or from plain text retrieval to complex multimedia search engines and thus fosters the reproducibility of evaluations.


## Background
Systems evaluations are an important part of development and empirical research in computer science. They encompass the systematic assessment of the run-time characteristics of systems based on one or several parameters. Considering all possible parameter settings is often a very tedious and time-consuming task with many manual activities.


## Getting started

### Requirements

* MySQL server database
* PHP 5.6 or higher
* Apache2 with active mod_rewrite 
* Apache config `AllowOverride All` needs to be set
* Git


### Installation procedure
Copy the file `install.php` into the webroot folder of the server and call it in the browser.
Follow the instructions there to install the system and setup Chronos.

After a successful setup you can log in with `admin` as username and `password` as password. Before you start with your first evaluations, adjust the settings in the admin interface according to your environment and your requirements.


### First evaluation
To perform your first evaluation, please follow the following steps:

1. Integrate the [Chronos Agent](https://github.com/Chronos-EaaS/Chronos-Agent) in your existing evaluation client.
2. Register your system in Chronos Control _(Systems -> Add system)_. Please notice that the VCS settings are optional.
3. Configure the parameters of your evaluation client _(Systems -> \[your system\] -> Configure Parameters)_.
4. Configure how the results of a single evaluation job should be visualized _(Systems -> \[your system\] -> Configure Job Results)_.
5. Similar to this, configure how the results of an complete evaluation (consisting of multiple jobs) should be visualized _(Systems -> [your system] -> Configure Overall Results)_.
6. Get the system id _(Systems -> \[your system\] -> System ID)_ and add it to your evaluation client.
7. Start your client and specify your Chronos Control deployment.
8. In Chronos Control, create a project _(Projects -> Add Project)_ for your evaluation campaign.
8. Create an experiment _(Projects -> \[your project\] -> Create Experiment)_ within your project.
9. Trigger the first evaluation _(Projects -> \[your project\] -> \[your experiment\] -> Run evaluation)_.


## Roadmap
See the [open issues](https://github.com/Chronos-EaaS/Chronos-Control/issues) for a list of proposed features (and known issues).


## Contributing
We highly welcome contributions to the Chronos project. If you would like to contribute, please fork the repository and submit your changes as a pull request.


## Credits
The Chronos user interface shines thanks to the beautiful [AdminLTE](https://adminlte.io/) template.


## License
The MIT License (MIT)