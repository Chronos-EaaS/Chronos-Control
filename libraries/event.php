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

use DBA\Event;

class Event_Library {
    const TYPE_JOB = "job";
    const TYPE_EVALUATION = "evaluation";
    const TYPE_EXPERIMENT = "experiment";
    const TYPE_PROJECT = "project";

    const TIME_DIFF = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );

    /**
     * @param $events Event[]
     * @return string
     */
    public function renderTimeline($events) {
        if (sizeof($events) == 0) {
            return "";
        }
        $output = '<ul class="timeline" style="margin-bottom: 50px;">';
        // TODO: if there is a related object, also create a link to access this object
        // TODO: if a user is involved also mention it and provide the link
        foreach ($events as $event) {
            $output .= "<li><i class='" . $this->getIconClasses($event) . "'></i>";
            $output .= "<div class='timeline-item'><span class='time'><i class='fa fa-clock-o'></i> " . $this->getTime($event) . "</span>";
            $output .= "<h3 class='timeline-header'>" . $event->getTitle() . "</h3>";
            $output .= "<div class='timeline-body'>" . $event->getEventText() . "</div>";
            $output .= "<div class='timeline-footer'>" . $this->buildFooter($event) . "</div>";
            $output .= "</div></li>";
        }
        $output .= "<li><i class='fa fa-clock-o bg-gray'></i></li>";
        $output .= "</ul>";
        return $output;
    }

    /**
     * @param $event Event
     * @return string
     */
    private function getIconClasses($event) {
        $classes = "fa ";
        switch ($event->getEventType()) {
            case Event_Library::TYPE_EVALUATION:
                $classes .= "fa-paper-plane-o bg-olive";
                break;
            case Event_Library::TYPE_EXPERIMENT:
                $classes .= "fa-wrench bg-navy";
                break;
            case Event_Library::TYPE_JOB:
                $classes .= "fa-server bg-aqua";
                break;
            case Event_Library::TYPE_PROJECT:
                $classes .= "fa-archive bg-blue";
                break;
            default:
                $classes .= "bg-grey";
                break;
        }
        return $classes;
    }

    /**
     * @param $type string
     * @return string
     */
    private function getButtonClasses($type) {
        $classes = "btn btn-xs ";
        switch ($type) {
            case 'job':
                $classes .= "bg-aqua";
                break;
            case 'user':
                $classes .= "bg-purple";
                break;
            case 'evaluation':
                $classes .= "bg-olive";
                break;
            case 'experiment':
                $classes .= "bg-navy";
                break;
            case 'project': // blue
                $classes .= "btn-primary";
                break;
            default:
                $classes .= "btn-default";
                break;
        }
        return $classes;
    }

    /**
     * @param $event Event
     * @return string
     */
    private function getTime($event) {
        $now = new DateTime();
        $ago = new DateTime($event->getTime());
        $diff = $now->diff($ago);

        // handle specially to get weeks
        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $text = array();
        foreach (Event_Library::TIME_DIFF as $short => $name) {
            if ($diff->$short > 0) {
                $text[] = $diff->$short . ' ' . $name . ($diff->$short > 1 ? 's' : '');
            }
        }
        if (sizeof($text) == 0) {
            return "just now";
        }
        return implode(", ", array_slice($text, 0, 1)) . " ago";
    }

    /**
     * @param $event Event
     * @return string
     */
    private function buildFooter($event) {
        global $FACTORIES;

        $build = [];
        $arr = [];
        if ($event->getEventType() == Define::EVENT_JOB) {
            $job = $FACTORIES::getJobFactory()->get($event->getRelatedId());
            $build[] = "<a href='/job/detail/id=" . $job->getId() . "' class='" . $this->getButtonClasses('job') . "'>Job #" . $job->getInternalId() . "</a>&nbsp;";
            $arr['evaluation'] = $job->getEvaluationId();
        }
        if ($event->getEventType() == Define::EVENT_EVALUATION || isset($arr['evaluation'])) {
            $evaluation = $FACTORIES::getEvaluationFactory()->get((isset($arr['evaluation'])) ? $arr['evaluation'] : $event->getRelatedId());
            $build[] = "<a href='/evaluation/detail/id=" . $evaluation->getId() . "' class='" . $this->getButtonClasses('evaluation') . "'>" . $evaluation->getName() . "</a>&nbsp;";
            $arr['experiment'] = $evaluation->getExperimentId();
        }
        if ($event->getEventType() == Define::EVENT_EXPERIMENT || isset($arr['experiment'])) {
            $experiment = $FACTORIES::getExperimentFactory()->get((isset($arr['experiment'])) ? $arr['experiment'] : $event->getRelatedId());
            $build[] = "<a href='/experiment/detail/id=" . $experiment->getId() . "' class='" . $this->getButtonClasses('experiment') . "'>" . $experiment->getName() . "</a>&nbsp;";
            $arr['project'] = $experiment->getProjectId();
        }
        if ($event->getEventType() == Define::EVENT_PROJECT || isset($arr['project'])) {
            $project = $FACTORIES::getProjectFactory()->get((isset($arr['project'])) ? $arr['project'] : $event->getRelatedId());
            $build[] = "<a href='/project/detail/id=" . $project->getId() . "' class='" . $this->getButtonClasses('project') . "'>" . $project->getName() . "</a>&nbsp;";
        }
        if ($event->getUserId() > 0) {
            $user = $FACTORIES::getUserFactory()->get($event->getUserId());
            $build[] = "<a class='" . $this->getButtonClasses('user') . "'>" . $user->getFirstname() . " " . $user->getLastname() . "</a>&nbsp;";
        }

        return implode("&nbsp", array_reverse($build));
    }
}