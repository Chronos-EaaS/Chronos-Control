-- phpMyAdmin SQL Dump
-- version 4.7.8
-- https://www.phpmyadmin.net/
--

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `Evaluation` (
  `evaluationId` int(11) NOT NULL,
  `name` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `systemId` int(11) NOT NULL,
  `experimentId` int(11) NOT NULL,
  `internalId` int(11) NOT NULL,
  `isArchived` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `Event` (
  `eventId` int(11) NOT NULL,
  `title` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `time` datetime NOT NULL,
  `eventText` text COLLATE utf8_unicode_ci NOT NULL,
  `eventType` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `relatedId` int(11) DEFAULT NULL,
  `userId` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `Experiment` (
  `experimentId` int(11) NOT NULL,
  `name` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `userId` int(11) NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `systemId` int(11) NOT NULL,
  `phases` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `created` datetime NOT NULL,
  `projectId` int(11) NOT NULL,
  `postData` text COLLATE utf8_unicode_ci NOT NULL,
  `internalId` int(11) NOT NULL,
  `isArchived` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `Job` (
  `jobId` int(11) NOT NULL,
  `userId` int(11) NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `systemId` int(11) NOT NULL,
  `environment` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `phases` int(11) NOT NULL,
  `cdl` text COLLATE utf8_unicode_ci NOT NULL,
  `status` int(11) NOT NULL,
  `progress` int(11) NOT NULL,
  `result` mediumtext COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `started` datetime DEFAULT NULL,
  `finished` datetime DEFAULT NULL,
  `evaluationId` int(11) NOT NULL,
  `internalId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `Project` (
  `projectId` int(11) NOT NULL,
  `name` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `userId` int(11) NOT NULL,
  `systemId` int(11) NOT NULL,
  `isFinished` tinyint(4) NOT NULL,
  `environment` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `isArchived` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `ProjectUser` (
  `projectUserId` int(11) NOT NULL,
  `userId` int(11) NOT NULL,
  `projectId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `Result` (
  `resultId` int(11) NOT NULL,
  `data` text COLLATE utf8_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `Session` (
  `sessionId` int(11) NOT NULL,
  `selector` varchar(20) COLLATE utf8_unicode_ci NOT NULL,
  `token` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `userId` int(11) NOT NULL,
  `created` datetime DEFAULT NULL,
  `expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `Setting` (
  `settingId` int(11) NOT NULL,
  `section` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `item` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `value` blob NOT NULL,
  `systemId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `System` (
  `systemId` int(11) NOT NULL,
  `name` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `userId` int(11) NOT NULL,
  `vcsUrl` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `vcsBranch` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `vcsType` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
  `vcsUser` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `vcsPassword` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  `lastEdit` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


CREATE TABLE `User` (
  `userId` int(11) NOT NULL,
  `username` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(256) COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
  `lastname` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `firstname` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `gender` int(11) NOT NULL,
  `role` int(11) NOT NULL,
  `alive` tinyint(4) NOT NULL,
  `activated` tinyint(4) NOT NULL,
  `created` datetime NOT NULL,
  `lastEdit` datetime DEFAULT NULL,
  `lastLogin` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;


ALTER TABLE `Evaluation`
  ADD PRIMARY KEY (`evaluationId`);


ALTER TABLE `Event`
  ADD PRIMARY KEY (`eventId`);


ALTER TABLE `Experiment`
  ADD PRIMARY KEY (`experimentId`);


ALTER TABLE `Job`
  ADD PRIMARY KEY (`jobId`);


ALTER TABLE `Project`
  ADD PRIMARY KEY (`projectId`);


ALTER TABLE `ProjectUser`
  ADD PRIMARY KEY (`projectUserId`);


ALTER TABLE `Result`
  ADD PRIMARY KEY (`resultId`);


ALTER TABLE `Session`
  ADD PRIMARY KEY (`sessionId`);


ALTER TABLE `Setting`
  ADD PRIMARY KEY (`settingId`);


ALTER TABLE `System`
  ADD PRIMARY KEY (`systemId`);


ALTER TABLE `User`
  ADD PRIMARY KEY (`userId`);


ALTER TABLE `Evaluation`
  MODIFY `evaluationId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;


ALTER TABLE `Event`
  MODIFY `eventId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;


ALTER TABLE `Experiment`
  MODIFY `experimentId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;


ALTER TABLE `Job`
  MODIFY `jobId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;


ALTER TABLE `Project`
  MODIFY `projectId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;


ALTER TABLE `ProjectUser`
  MODIFY `projectUserId` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `Result`
  MODIFY `resultId` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `Session`
  MODIFY `sessionId` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `Setting`
  MODIFY `settingId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;


ALTER TABLE `System`
  MODIFY `systemId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;


ALTER TABLE `User`
  MODIFY `userId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
