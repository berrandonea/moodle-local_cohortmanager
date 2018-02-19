<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Initially developped for :
 * Université de Cergy-Pontoise
 * 33, boulevard du Port
 * 95011 Cergy-Pontoise cedex
 * FRANCE
 *
 * Adds to the course a section where the teacher can submit a problem to groups of students
 * and give them various collaboration tools to work together on a solution.
 *
 * @package   local_cohortmanager
 * @copyright 2017 Laurent Guillet <laurent.guillet@u-cergy.fr>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * File : createcohorts.php
 * Create cohorts, assign cohort members and fill table local_cohortmanager_info
 */

define('CLI_SCRIPT', true);
require_once( __DIR__.'/../../config.php');

require_once($CFG->dirroot .'/cohort/lib.php');
require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/filelib.php');

$xmldoc = new DOMDocument();
$fileopening = $xmldoc->load('/home/referentiel/dokeos_elp_etu_ens.xml');
if ($fileopening == false) {
    echo "Impossible de lire le fichier source.\n";
}
$xpathvar = new Domxpath($xmldoc);

$listtreatedgroups = array();

$groups = $xpathvar->query('//Structure_diplome/Cours/Group');

$timesync = time();

foreach ($groups as $group) {

    $vet = $group->parentNode->parentNode;
    $idvet = $vet->getAttribute('Etape');
    $idvetyear = "Y2017-$idvet";

    $cours = $group->parentNode;
    $courselp = $cours->getAttribute('element_pedagogique');

    $groupcode = $group->getAttribute('GroupCode');

    $cohortcode = "Y2017-".$idvet."-".$groupcode;

    if (!in_array($cohortcode, $listtreatedgroups)) {

        if (substr($idvet, 0, 1) == 1 && $courselp != "" && $groupcode != "") {

            $category = $DB->get_record('course_categories', array('idnumber' => $idvetyear));
            $parentcategory = $DB->get_record('course_categories', array('id' => $category->parent));
            $contextidparentcategory = $DB->get_record('context',
                            array('contextlevel' => 40, 'instanceid' => $parentcategory->id))->id;

            $tableteachername = array();

            if ($DB->record_exists('cohort', array('idnumber' => $cohortcode,
                'contextid' => $contextidparentcategory))) {

                $cohort = $DB->get_record('cohort', array('idnumber' => $cohortcode,
                    'contextid' => $contextidparentcategory));

                $cohortid = $cohort->id;

                echo "La cohorte ".$cohort->name." existe\n";

                $listcohortmembers = $DB->get_records('cohort_members',
                            array('cohortid' => $cohortid));

                $listtempcohortmembers = array();

                foreach ($listcohortmembers as $cohortmembers) {

                    $tempcohortmember = new stdClass();
                    $tempcohortmember->userid = $cohortmembers->userid;
                    $tempcohortmember->stillexists = 0;

                    $listtempcohortmembers[] = $tempcohortmember;
                }

                $group->removeChild($group->lastChild);

                foreach ($group->childNodes as $groupmember) {

                    if ($groupmember->nodeType !== 1 ) {
                        continue;
                    }

                    $username = $groupmember->getAttribute('StudentUID');
                    $tableteachername[] = $groupmember->getAttribute('StaffUID');

                    if ($DB->record_exists('user',
                            array('username' => $username))) {

                        $memberid = $DB->get_record('user',
                                    array('username' => $username))->id;

                        if ($DB->record_exists('cohort_members',
                                    array('cohortid' => $cohortid, 'userid' => $memberid))) {

                            foreach ($listtempcohortmembers as $tempcohortmember) {

                                if ($tempcohortmember->userid == $memberid) {

                                    $tempcohortmember->stillexists = 1;
                                }
                            }
                        } else {

                            echo "Inscription de l'utilisateur ".$username."\n";

                            cohort_add_member($cohortid, $memberid);

                            echo "Utilisateur inscrit\n";
                        }
                    }
                }

                if (isset($listtempcohortmembers)) {

                    foreach ($listtempcohortmembers as $tempcohortmember) {

                        if ($tempcohortmember->stillexists == 0) {

                            echo "Désinscription de l'utilisateur $tempcohortmember->userid\n";

                            cohort_remove_member($cohortid, $tempcohortmember->userid);

                            echo "Utilisateur désinscrit\n";
                        }
                    }
                }
            } else {

                $cohort = new stdClass();
                $cohort->contextid = $contextidparentcategory;
                $cohort->name = $group->getAttribute('GroupName')." ($idvet-$groupcode)";
                $cohort->idnumber = $cohortcode;
                $cohort->component = 'local_cohortmanager';

                echo "La cohorte ".$cohort->name." n'existe pas\n";

                $cohortid = cohort_add_cohort($cohort);

                echo "Elle est créée.\n";

                $group->removeChild($group->lastChild);
                $groupmembers = $group->childNodes;

                foreach ($groupmembers as $groupmember) {

                    if ($groupmember->nodeType !== 1 ) {
                        continue;
                    }

                    $username = $groupmember->getAttribute('StudentUID');
                    $tableteacherid[] = $groupmember->getAttribute('StaffUID');

                    if ($DB->record_exists('user',
                            array('username' => $username))) {

                        echo "Inscription de l'utilisateur ".$username."\n";

                        $memberid = $DB->get_record('user',
                                array('username' => $username))->id;

                        cohort_add_member($cohortid, $memberid);

                        echo "Utilisateur inscrit\n";
                    }
                }
            }

            $listtreatedgroups[] = $cohortcode;
        }
    }

    foreach ($tableteachername as $teachername) {

        if ($DB->record_exists('user', array('username' => $teachername))) {

            $teacherid = $DB->get_record('user', array('username' => $teachername))->id;
            // Ici, rajouter l'entrée dans local_cohortmanager_info.

            $yearlycourselp = "Y2017-".$courselp;

            if ($DB->record_exists('local_cohortmanager_info',
                    array('cohortid' => $cohortid, 'teacherid' => $teacherid,
                        'codeelp' => $yearlycourselp))) {

                // Update record.

                $cohortinfo = $DB->get_record('local_cohortmanager_info',
                    array('cohortid' => $cohortid, 'teacherid' => $teacherid,
                        'codeelp' => $yearlycourselp));

                $cohortinfo->timesynced = $timesync;

                $DB->update_record('local_cohortmanager_info', $cohortinfo);

            } else {

                $cohortinfo = new stdClass();
                $cohortinfo->cohortid = $cohortid;
                $cohortinfo->teacherid = $teacherid;
                $cohortinfo->codeelp = $yearlycourselp;
                $cohortinfo->timesynced = $timesync;

                $DB->insert_record('local_cohortmanager_info', $cohortinfo);
            }
        }
    }
}



$xmldoc = new DOMDocument();
$fileopening = $xmldoc->load('/home/referentiel/dokeos_elp_ens.xml');
if ($fileopening == false) {
    echo "Impossible de lire le fichier source.\n";
}
$xpathvar = new Domxpath($xmldoc);

$groups = $xpathvar->query('//Structure_diplome/Teacher/Cours/Group');

foreach ($groups as $group) {

    $vet = $group->parentNode->parentNode->parentNode;
    $idvet = $vet->getAttribute('Etape');
    $idvetyear = "Y2017-$idvet";

    $cours = $group->parentNode;
    $courselp = $cours->getAttribute('element_pedagogique');

    $groupcode = $group->getAttribute('GroupCode');

    $cohortcode = "Y2017-".$idvet."-".$groupcode;

    if (substr($idvet, 0, 1) == 1 && $courselp != "" && $groupcode != "") {

        if (!in_array($cohortcode, $listtreatedgroups)) {

            $category = $DB->get_record('course_categories', array('idnumber' => $idvetyear));
            $parentcategory = $DB->get_record('course_categories', array('id' => $category->parent));
            $contextidparentcategory = $DB->get_record('context',
                            array('contextlevel' => 40, 'instanceid' => $parentcategory->id))->id;

            if (!$DB->record_exists('cohort', array('idnumber' => $cohortcode,
                'contextid' => $contextidparentcategory))) {

                $cohort = new stdClass();
                $cohort->contextid = $contextidparentcategory;
                $cohort->name = $group->getAttribute('GroupName')." ($idvet-$groupcode)";
                $cohort->idnumber = $cohortcode;
                $cohort->component = 'local_cohortmanager';

                echo "La cohorte ".$cohort->name." n'existe pas\n";

                $cohortid = cohort_add_cohort($cohort);

                echo "Elle est créée.\n";
            }

            $listtreatedgroups[] = $cohortcode;
        } else {

            echo "Cohorte $cohortcode déjà traitée\n";
        }
    }

    // Ici, rajouter l'entrée dans local_cohortmanager_info.

    $yearlycourselp = "Y2017-".$courselp;

    if ($DB->record_exists('local_cohortmanager_info',
            array('cohortid' => $cohortid,
                'codeelp' => $yearlycourselp))) {

        // Update record.

        $cohortinfo = $DB->get_record('local_cohortmanager_info',
            array('cohortid' => $cohortid,
                'codeelp' => $yearlycourselp));

        $cohortinfo->timesynced = $timesync;

        $DB->update_record('local_cohortmanager_info', $cohortinfo);

    } else {

        $cohortinfo = new stdClass();
        $cohortinfo->cohortid = $cohortid;
        $cohortinfo->teacherid = null;
        $cohortinfo->codeelp = $yearlycourselp;
        $cohortinfo->timesynced = $timesync;

        $DB->insert_record('local_cohortmanager_info', $cohortinfo);
    }
}

if (!$DB->record_exists('cohort', array('idnumber' => 1,
                'contextid' => context_system::instance()->id))) {

    $cohort = new stdClass();
    $cohort->contextid = context_system::instance()->id;
    $cohort->name = "Etudiants 2017";
    $cohort->idnumber = 1;
    $cohort->component = 'local_cohortmanager';

    $cohortid = cohort_add_cohort($cohort);
} else {

    $cohortid = $DB->get_record('cohort', array('idnumber' => 1,
                'contextid' => context_system::instance()->id))->id;
}

$listcohortmembers = $DB->get_records('cohort_members',
                            array('cohortid' => $cohortid));

$listtempcohortmembers = array();

foreach ($listcohortmembers as $cohortmembers) {

    $tempcohortmember = new stdClass();
    $tempcohortmember->userid = $cohortmembers->userid;
    $tempcohortmember->stillexists = 0;

    $listtempcohortmembers[] = $tempcohortmember;
}

$xmldoc = new DOMDocument();
$fileopening = $xmldoc->load('/home/referentiel/DOKEOS_Etudiants_Inscriptions.xml');
if ($fileopening == false) {
    echo "Impossible de lire le fichier source.\n";
}
$xpathvar = new Domxpath($xmldoc);

$anneunivs = $xpathvar->query('//Student/Annee_universitaire[@AnneeUniv=2017]');

foreach ($anneunivs as $anneuniv) {

    $student = $anneuniv->parentNode;
    $username = $student->getAttribute('StudentUID');

    if ($DB->record_exists('user', array('username' => $username))) {

        $memberid = $DB->get_record('user',
                    array('username' => $username))->id;

        if ($DB->record_exists('cohort_members',
                    array('cohortid' => $cohortid, 'userid' => $memberid))) {

            foreach ($listtempcohortmembers as $tempcohortmember) {

                if ($tempcohortmember->userid == $memberid) {

                    $tempcohortmember->stillexists = 1;
                }
            }
        } else {

            echo "Inscription de l'utilisateur ".$username."\n";

            cohort_add_member($cohortid, $memberid);

            echo "Utilisateur inscrit\n";
        }
    }
}

if ($DB->record_exists('local_cohortmanager_info',
        array('cohortid' => $cohortid, 'teacherid' => null,
            'codeelp' => 1))) {

    // Update record.

    $cohortinfo = $DB->get_record('local_cohortmanager_info',
        array('cohortid' => $cohortid, 'teacherid' => null,
            'codeelp' => 1));

    $cohortinfo->timesynced = $timesync;

    $DB->update_record('local_cohortmanager_info', $cohortinfo);

} else {

    $cohortinfo = new stdClass();
    $cohortinfo->cohortid = $cohortid;
    $cohortinfo->teacherid = null;
    $cohortinfo->codeelp = 1;
    $cohortinfo->timesynced = $timesync;

    $DB->insert_record('local_cohortmanager_info', $cohortinfo);
}

if (isset($listtempcohortmembers)) {

    foreach ($listtempcohortmembers as $tempcohortmember) {

        if ($tempcohortmember->stillexists == 0) {

            echo "Désinscription de l'utilisateur $tempcohortmember->userid\n";

            cohort_remove_member($cohortid, $tempcohortmember->userid);

            echo "Utilisateur désinscrit\n";
        }
    }
}

$selectdeleteoldcohortinfo = "timesynced < $timesync";
$DB->delete_records_select('local_cohortmanager_info', $selectdeleteoldcohortinfo);