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
use DBA\Factory;

class Event_Library {
    const TYPE_JOB = "job";
    const TYPE_EVALUATION = "evaluation";
    const TYPE_EXPERIMENT = "experiment";
    const TYPE_PROJECT = "project";
    const TYPE_USER = "user";

    const TIME_DIFF = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];

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
            $output .= "<div class='timeline-item'><span class='time' title='" . $this->getIsoTime($event) . "'><i class='fa fa-clock'></i> " . $this->getTime($event) . "</span>";
            $output .= "<h3 class='timeline-header'>" . $event->getTitle() . "</h3>";
            $output .= "<div class='timeline-body'>" . $event->getEventText() . "</div>";
            $output .= "<div class='timeline-footer'>" . $this->buildFooter($event) . "</div>";
            $output .= "</div></li>";
        }
        $output .= "<li><i class='fa fa-clock bg-gray'></i></li>";
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
                $classes .= "fa-paper-plane bg-olive";
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
            case Event_Library::TYPE_USER:
                $classes .= "fa-user bg-purple";
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
            case Event_Library::TYPE_JOB:
                $classes .= "bg-aqua";
                break;
            case Event_Library::TYPE_USER:
                $classes .= "bg-purple";
                break;
            case Event_Library::TYPE_EVALUATION:
                $classes .= "bg-olive";
                break;
            case Event_Library::TYPE_EXPERIMENT:
                $classes .= "bg-navy";
                break;
            case Event_Library::TYPE_PROJECT:
                $classes .= "btn-primary"; // blue
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
     * @throws Exception
     */
    private function getTime($event) {
        $now = new DateTime();
        $ago = new DateTime($event->getTime());
        $diff = $now->diff($ago);

        // handle specially to get weeks
        $w = floor($diff->d / 7);
        $diff->d -= $w * 7;

        $text = [];
        foreach (Event_Library::TIME_DIFF as $short => $name) {
            if ($short !== 'w' and $diff->$short > 0) {
                $text[] = $diff->$short . ' ' . $name . ($diff->$short > 1 ? 's' : '');
            } else if ($short !== 'w' and $diff->$short > 0) {
                $text[] = $w . ' ' . $name . ($w > 1 ? 's' : '');
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
     * @throws Exception
     */
    private function getIsoTime($event) {
        return (new DateTime($event->getTime()))->format('Y-m-d H:i');
    }


    /**
     * @param $event Event
     * @return string
     */
    private function buildFooter($event) {
        $build = [];
        $arr = [];
        if ($event->getEventType() == Define::EVENT_JOB) {
            $job = Factory::getJobFactory()->get($event->getRelatedId());
            $build[] = "<a href='/job/detail/id=" . $job->getId() . "' class='" . $this->getButtonClasses('job') . "'>Job #" . $job->getInternalId() . "</a>&nbsp;";
            $arr['evaluation'] = $job->getEvaluationId();
        }
        if ($event->getEventType() == Define::EVENT_EVALUATION || isset($arr['evaluation'])) {
            $evaluation = Factory::getEvaluationFactory()->get((isset($arr['evaluation'])) ? $arr['evaluation'] : $event->getRelatedId());
            $build[] = "<a href='/evaluation/detail/id=" . $evaluation->getId() . "' class='" . $this->getButtonClasses('evaluation') . "'>" . $evaluation->getName() . "</a>&nbsp;";
            $arr['experiment'] = $evaluation->getExperimentId();
        }
        if ($event->getEventType() == Define::EVENT_EXPERIMENT || isset($arr['experiment'])) {
            $experiment = Factory::getExperimentFactory()->get((isset($arr['experiment'])) ? $arr['experiment'] : $event->getRelatedId());
            $build[] = "<a href='/experiment/detail/id=" . $experiment->getId() . "' class='" . $this->getButtonClasses('experiment') . "'>" . $experiment->getName() . "</a>&nbsp;";
            $arr['project'] = $experiment->getProjectId();
        }
        if ($event->getEventType() == Define::EVENT_PROJECT || isset($arr['project'])) {
            $project = Factory::getProjectFactory()->get((isset($arr['project'])) ? $arr['project'] : $event->getRelatedId());
            $build[] = "<a href='/project/detail/id=" . $project->getId() . "' class='" . $this->getButtonClasses('project') . "'>" . $project->getName() . "</a>&nbsp;";
        }
        if ($event->getUserId() > 0) {
            $user = Factory::getUserFactory()->get($event->getUserId());
            $build[] = "<a class='" . $this->getButtonClasses('user') . "'>" . $user->getFirstname() . " " . $user->getLastname() . "</a>&nbsp;";
        }

        return implode("&nbsp", array_reverse($build));
    }
}