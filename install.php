<?php

/*
The MIT License (MIT)

Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

/*
  check prerequisites:
  - write permissions
  - check for webroot (which one is the root web folder)
  - check if git or hg
  - do clone etc
  - fill sql data
  - create initial user
  - create htaccess forward
*/

$messages = array();

//check permissions
if (!is_writable(".")) {
    $messages[] = "<p style='color: #FF0000;'>I need write permissions on current directory!</p>";
} else if (!`which git` && !`which hg`) {
    $messages[] = "<p style='color: #FF0000;'>No VCS available, you need at least git or mercurial installed!</p>";
}

if (isset($_POST['submit'])) {
    doInstallation();
}

function doInstallation() {
    global $messages;

    // handle input
    $repoType = $_POST['repoType'];
    $repoUrl = $_POST['repoUrl'];
    $repoPassword = $_POST['repoPassword'];
    $repoBranch = $_POST['repoBranch'];
    $repoUsername = $_POST['repoUsername'];

    $dbServer = $_POST['dbServer'];
    $dbUsername = $_POST['dbUsername'];
    $dbPassword = $_POST['dbPassword'];
    $dbDatabase = $_POST['dbDatabase'];

    $output = array();

    if (file_exists("chronos") && is_dir("chronos")) {
        // delete chronos folder if it already exists
        $output[] = shell_exec("rm -r chronos");
    }

    // clone repository
    switch ($repoType) {
        case 'git':
            $split = explode("//", $repoUrl);
            $protocol = $split[0];
            unset($split[0]);
            $url = implode("//", $split);
            $output[] = shell_exec("git clone '" . escapeshellarg($protocol) . "//" . escapeshellarg($repoUsername) . ":" . escapeshellarg($repoPassword) . "@" . escapeshellarg($url) . "' chronos");
            $output[] = shell_exec("cd chronos && git checkout '" . escapeshellarg($repoBranch) . "''");
            break;
        case 'hg':
            $output[] = shell_exec("hg clone --config auth.x.prefix=* --config auth.x.username='" . escapeshellarg($repoUsername) . "' --config auth.x.password='" . escapeshellarg($repoPassword) . "' '" . escapeshellarg($repoUrl) . "' chronos");
            $output[] = shell_exec("cd chronos && hg update '" . escapeshellarg($repoBranch) . "'");
            break;
        default:
            $messages[] = "<p style='color: #FF0000;'>Invalid VCS type!</p>";
            return;
    }

    // load initial sql
    if (!file_exists(dirname(__FILE__) . "/chronos/chronos.sql")) {
        $messages[] = "<p style='color: #FF0000;'>Initial SQL file not found!</p>";
        return;
    }
    $sql = file_get_contents(dirname(__FILE__) . "/chronos/chronos.sql");

    // connect to db and set up
    try {
        $db = new PDO('mysql:host=' . $dbServer . ';dbname=' . $dbDatabase . ';charset=utf8mb4', $dbUsername, $dbPassword);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->query($sql);
    } catch (PDOException $e) {
        $messages[] = "<p style='color: #FF0000;'>SQL Setup failed: " . $e->getMessage() . "</p>";
        return;
    }

    // TODO: read this from input form (password, email, username, lastname, firstname)
    $password = "password";
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $email = "jane.doe@example.org";
    try {
        $db->beginTransaction();
        $db->query("INSERT INTO User (`gender`, `lastname`, `firstname`, `username`, `password`, `email`, `alive`, `activated`, `created`, `lastEdit`, `role`)
                              VALUES (1, 'Smith', 'Debbie', 'admin', '$hash', '$email', 1, 1, '" . date('Y-m-d H:i:s') . "', '" . date('Y-m-d H:i:s') . "', 2);"
        );
        $db->query("INSERT INTO Setting (`settingId`, `section`, `item`, `value`, `systemId`) VALUES 
                                (NULL, 'vcs', 'repoType', '$repoType', 0),
                                (NULL, 'vcs', 'repoUrl', '$repoUrl', 0),
                                (NULL, 'vcs', 'repoUsername', '$repoUsername', 0),
                                (NULL, 'vcs', 'repoPassword', '$repoPassword', 0),
                                (NULL, 'vcs', 'repoBranch', '$repoBranch', 0),
                                (NULL, 'mail', 'mailHost', '', 0),
                                (NULL, 'mail', 'mailPort', '25', 0),
                                (NULL, 'mail', 'mailUsername', '', 0),
                                (NULL, 'mail', 'mailPassword', '', 0),
                                (NULL, 'mail', 'mailFrom', 'chronos@example.org', 0),
                                (NULL, 'mail', 'mailFromName', 'Chronos Control', 0),
                                (NULL, 'ftp', 'ftpServer', '', 0),
                                (NULL, 'ftp', 'ftpPort', '21', 0),
                                (NULL, 'ftp', 'ftpUsername', '', 0),
                                (NULL, 'ftp', 'ftpPassword', '', 0),
                                (NULL, 'ftp', 'localNetworkCIDR', '', 0),
                                (NULL, 'ftp', 'useFtpUploadForLocalClients', '1', 0),
                                (NULL, 'other', 'rowsPerPage', '20', 0),
                                (NULL, 'other', 'descriptionLength', '300', 0),
                                (NULL, 'other', 'maxJobsPerEvaluation', '1000', 0),
                                (NULL, 'other', 'uploadedDataHostname', 'https://chronos.example.org', 0);
        ");
        $db->commit();
    } catch (PDOException $e) {
        $messages[] = "<p style='color: #FF0000;'>Insert of user failed: " . $e->getMessage() . "</p>";
        return;
    }

    $config = file_get_contents(dirname(__FILE__) . "/chronos/config.template.php");
    $config = str_replace("__DB_HOST__", $dbServer, $config);
    $config = str_replace("__DB_USER__", $dbUsername, $config);
    $config = str_replace("__DB_PASS__", $dbPassword, $config);
    $config = str_replace("__DB_NAME__", $dbDatabase, $config);
    file_put_contents(dirname(__FILE__) . "/chronos/config.php", $config);

    $access = file_get_contents(dirname(__FILE__) . "/chronos/.htaccess");
    $access = str_replace("webroot/", "chronos/webroot/", $access);
    file_put_contents(dirname(__FILE__) . "/.htaccess", $access);
    $messages[] = "<p style='color: #1da845;'>Setup successful! Click <a href='index.php'>here</a> to continue.</p>";
}

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Chronos Installation</title>
  <style type="text/css">
    input, select {
      width: 585px;
      margin: 0 0 10px 0;
      padding: 5px;
    }
  </style>
</head>
<body>
<div style='width: 600px; margin: 30px auto; padding: 10px; background-color: #DDDDDD;'>
  <h1>Chronos Installation</h1>
  <p>
    Welcome to the Chronos installation procedure!
  </p>
    <?php
    foreach ($messages as $message) {
        echo $message;
    }
    ?>
  <hr>
  <form action="install.php" method="post">
    <p>Data Source</p>
    <select name="repoType" title="Repository Type">
        <?php if (`which git`) { ?>
          <option value="git">Git</option>
        <?php }
        if (`which hg`) { ?>
          <option value="hg">Mercurial</option>
        <?php } ?>
    </select>
    <input type="text" name="repoUrl" value="https://github.com/Chronos-EaaS/Chronos-Control.git"
           required><br>
    <input type="text" name="repoUsername" placeholder="Repository Username (Optional)"><br>
    <input type="password" name="repoPassword" placeholder="Repository Password (Optional)"><br>
    <input type="text" name="repoBranch" value="master" required><br>

    <hr>
    <p>Database Configuration</p>
    <input type="text" name="dbServer" placeholder="Server Host" required><br>
    <input type="text" name="dbDatabase" placeholder="Database Name" required><br>
    <input type="text" name="dbUsername" placeholder="Username" required><br>
    <input type="password" name="dbPassword" placeholder="Password" required><br><br>
    <input type="submit" name="submit" value="Set up">
  </form>
</div>
</body>
</html>
