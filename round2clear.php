<?php
function round2clear($RoundID){
    $today = date("d/m/y H:i");
    spl_autoload_register(function ($class_name) {
        if (file_exists("model/" . $class_name . ".php")) {
            require_once "model/" . $class_name . ".php";
        }
        elseif (file_exists($class_name . ".php")) {
            require_once $class_name . ".php";
        }
    });
    //opening required database connections
    $StudentDAO = new StudentDAO;
    $SectionDAO = new SectionDAO;
    $BidDAO = new BidDAO;
    $BidHistoryDAO = new BidHistoryDAO;
    $CourseDAO = new CourseDAO;
    $Round2DAO = new Round2DAO;

    $all_bids = $BidDAO -> retrieveAll();
    while (!empty($all_bids)) {
        $current_process = $all_bids[0];
        $current_courseid = $current_process -> getCourseID();
        $current_sectionid = $current_process -> getSectionID();
        $current_section = $SectionDAO -> retrieveSectionDetailsBySectionCourse($current_courseid, $current_sectionid);
        $current_aval = $current_section -> getSize();
        $Min_Bid = $Round2DAO -> GetMinBidSizefromCourseSection($current_courseid, $current_sectionid);

        //finding all other bids in the Bid table that matches the section+course combo
        $all_current_bids = $BidDAO -> SearchBidsbyCourseSection($current_courseid,$current_sectionid);
        if (sizeof($all_current_bids) <= $current_aval) {
            foreach ($all_current_bids as $successful_bid) {
                $successful_student = $successful_bid -> getUserID();
                $successful_amount = $successful_bid -> getAmount();
                $BidHistoryDAO -> addBid($successful_student,$successful_amount,$current_courseid,$current_sectionid,$today,'1',$RoundID);
                $BidDAO -> DeleteBid($successful_student, $current_courseid, $current_sectionid);
                $current_section = $SectionDAO -> retrieveSectionDetailsBySectionCourse($current_courseid, $current_sectionid);
                $remaining_aval = $current_section -> getSize();
                $remaining_aval --;
                $SectionDAO -> updateSize($current_courseid, $current_sectionid, $remaining_aval);
            }
        }
        else {
            // getting all current information to the array
            $all_competing_bids = [];
            foreach ($all_current_bids as $pending_bid) {
                $pending_userid = $pending_bid -> getUserID();
                $pending_amount = $pending_bid -> getAmount();
                $all_competing_bids[] = [$pending_amount,$pending_userid];
            }

            //sort the data by highest bid to lowest bid
            rsort($all_competing_bids);
            array_reverse($all_competing_bids);
            $remaining_aval = $current_section -> getSize();
            if ($remaining_aval == 0) {
                $remaining_aval = $Round2DAO -> GetSize($current_courseid, $current_sectionid);
            }

            //first clearing bid
            $clearing_bid = $all_competing_bids[$remaining_aval-1];
            $clearing_person = $clearing_bid[1];
            $clearing_amount = $clearing_bid[0];
            
            //second clearing bid
            $clearing_bid = $all_competing_bids[$remaining_aval];
            $clearing_checkname = $clearing_bid[1];
            $clearing_checkamount = $clearing_bid[0];

            if ($clearing_checkamount == $clearing_amount) {
                $stop = $current_aval - 1;
                $checksum = true;
                while ($checksum) {
                    $curr_check_amount = $all_competing_bids[$stop][0];
                    if ($curr_check_amount == $clearing_amount) {
                        if ($stop == 0) {
                            $clearing_amount ++;
                            break;
                        }
                        else {
                            $stop --;
                        }
                    }
                    else {
                        // set to exit loop
                        $clearing_amount = $curr_check_amount;
                        $checksum = false;
                    }
                }
            }
            
            //start to process and commit the changes in bid_history
            foreach ($all_competing_bids as $curr_bid) {
                $curr_name = $curr_bid[1];
                $curr_amount = $curr_bid[0];
                
                if ($curr_amount >= $clearing_amount && $remaining_aval != 0) {
                    $BidHistoryDAO -> addBid($curr_name,$curr_amount,$current_courseid,$current_sectionid,$today,'1',$RoundID);
                    $BidDAO -> DeleteBid($curr_name, $current_courseid, $current_sectionid);
                    $remaining_aval --;
                    $SectionDAO -> updateSize($current_courseid, $current_sectionid, $remaining_aval);
                }
                else {
                    $BidHistoryDAO -> addBid($curr_name,$curr_amount,$current_courseid,$current_sectionid,$today,'0',$RoundID);
                    $BidDAO -> DeleteBid($curr_name, $current_courseid, $current_sectionid);
                    //refund e-dollar to student
                    $refund_student_amount = $StudentDAO -> retrieveECredit($curr_name);
                    $refund_student_amount += $curr_amount;
                    $StudentDAO -> updateBid($curr_name, $refund_student_amount);
                }
            }
        }
        $all_bids = $BidDAO -> retrieveAll();
    }
}

 ?>
