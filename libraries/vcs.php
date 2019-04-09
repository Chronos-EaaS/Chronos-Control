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

class VCS_Library {

    /**
     * @param $path
     * @param $system DBA\System
     * @return string
     * @throws Exception
     */
    public static function cloneSystem($path, $system) {
        switch ($system->getVcsType()) {
            case 'git':
                $isHttps = strpos($system->getVcsUrl(), "https://") ? true : false;
                $url = str_replace("https://", "", str_replace("http://", "", $system->getVcsUrl()));
                $result = shell_exec("git clone " . ($isHttps ? "https://" : "http://") . $system->getVcsUser() . ":" . $system->getVcsPassword() . "@" . $url . " $path" . " 2>&1");
                $result .= shell_exec("cd " . $path . " && git checkout '" . escapeshellcmd($system->getVcsBranch()) . "' 2>&1");
                break;
            case 'hg':
                $result = shell_exec("hg clone --config auth.x.prefix=* --config auth.x.username='" . $system->getVcsUser() . "' --config auth.x.password='" . $system->getVcsPassword() . "' " . escapeshellcmd($system->getVcsUrl()) . " " . $path . " 2>&1");
                $result .= shell_exec("cd " . $path . " && " . "hg update -r " . "'" . escapeshellcmd($system->getVcsBranch()) . "'" . " -C" . " 2>&1");
                break;
            default:
                throw new Exception("Unknown VCS type on clone!");
        }
        return $result;
    }

    /**
     * @return string
     * @throws Exception
     */
    public function updateChronos() {
        Logger_Library::getInstance()->notice("Executing Chronos update. Current (old) revision: " . $this->getRevision(SERVER_ROOT, REPOSITORY_TYPE));
        $result = self::update(SERVER_ROOT, REPOSITORY_TYPE, REPOSITORY_BRANCH, REPOSITORY_URL, REPOSITORY_USER, REPOSITORY_PASS);
        Logger_Library::getInstance()->notice("Chronos update completed. New revision: " . $this->getRevision(SERVER_ROOT, REPOSITORY_TYPE));
        return $result;
    }

    /**
     * @param $path
     * @param $type
     * @param $branch
     * @param $repositoryUrl
     * @return string
     * @throws Exception
     */
    public static function update($path, $type, $branch, $repositoryUrl, $user, $pass) {
        switch ($type) {
            case 'git':
                $isHttps = strpos($repositoryUrl, "https://") ? true : false;
                $url = str_replace("https://", "", str_replace("http://", "", $repositoryUrl));
                //$result = shell_exec("cd " . $path . " && git checkout " . escapeshellcmd($branch) . " 2>&1");
                //$result = shell_exec("cd " . $path . " && git pull " . ($isHttps ? "https://" : "http://") . $user . ":" . $pass . "@" . $url . " 2>&1");
                $result = shell_exec("cd " . $path . " && git pull 2>&1");
                break;
            case 'hg':
                $result = shell_exec("cd " . $path . " && " . "hg pull --config auth.x.prefix=* --config auth.x.username='" . $user . "' --config auth.x.password='" . $pass . "'" . " 2>&1");
                $result .= shell_exec("cd " . $path . " && " . "hg update -r " . escapeshellcmd($branch) . " -C" . " 2>&1");
                break;
            default:
                throw new Exception("Unknown VCS type on update!");
        }
        return $result;
    }

    /**
     * Commit the changes in a repository (for systems).
     * Currently only supports git
     */
    public static function commit($path, $message) {
        $result = "No action performed";
        if (`which git`) {
            $result = shell_exec("cd '$path' 2>&1 && git pull 2>&1");
            $result .= shell_exec("cd '$path' && git add . 2>&1 && git commit -m '$message' 2>&1 && git push 2>&1");
        }
        return $result;
    }

    /**
     * @param $path
     * @param $type
     * @return string
     * @throws Exception
     */
    public static function getRevision($path, $type) {
        switch ($type) {
            case 'git':
                $result = exec("cd " . $path . " && git rev-parse HEAD");
                break;
            case 'hg':
                $result = exec("cd " . $path . " && " . "hg id -i");
                break;
            default:
                throw new Exception("Unknown VCS type on getRevision!");
        }
        return trim($result);
    }

    /**
     * @param $path
     * @param $type
     * @return string
     * @throws Exception
     */
    public static function getBranches($path, $type) {
        switch ($type) {
            case 'git':
                $result = shell_exec("cd " . $path . " && git branch | sed 's|  ||' | sed 's|* ||' | sort");
                break;
            case 'hg':
                $result = shell_exec('cd ' . $path . ' && ' . "hg branches --template='{branch}\n'");
                break;
            default:
                throw new Exception("Unknown VCS type on getBranches!");
        }
        return $result;
    }

    /**
     * based on: https://gist.github.com/geeknam/961488
     * @param $path
     * @param $type
     * @return string
     * @throws Exception
     */
    public static function getHistory($path, $type) {
        $output = array();
        switch ($type) {
            case 'git':
                exec("cd " . $path . " && git log", $output);
                break;
            case 'hg':
                exec('cd ' . $path . ' && ' . "hg log", $output);
                break;
            default:
                throw new Exception("Unknown VCS type on getLog!");
        }

        $history = array();
        foreach($output as $line){
            if(strpos($line, 'commit')===0){
                if(!empty($commit)){
                    array_push($history, $commit);
                    unset($commit);
                }
                $commit['hash'] = substr($line, strlen('commit'));
            } else if(strpos($line, 'Author')===0){
                $commit['author'] = substr($line, strlen('Author:'));
            } else if(strpos($line, 'Date')===0){
                $commit['date'] = substr($line, strlen('Date:'));
            } else {
                if (!empty($commit['message'])) {
                    $commit['message'] .= $line;
                } else {
                    $commit['message'] = $line;
                }
            }
            if(!empty($commit)) {
                array_push($history, $commit);
            }
        }
        return $history;
    }
}
