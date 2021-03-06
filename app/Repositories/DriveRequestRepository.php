<?php

namespace App\Repositories;

use Input, DB, App, Config;
use App\Models\DriveRequest;
use App\Models\Billing;
use DateTime;

class DriveRequestRepository{

	public function saveBookingRequest($generatedDriveCode, $inputs_array)
	{
	    $driveRequest = new DriveRequest();

        $driveRequest->drive_code 	           = $generatedDriveCode;
	    $driveRequest->customer_id 	           = $inputs_array['customer_id'];
		$driveRequest->pub_id                  = $inputs_array['pub_id'];
		$driveRequest->car_type_id 	           = $inputs_array['car_type_id'];
        $driveRequest->transmission_id         = $inputs_array['transmission_id'];
        $driveRequest->terms_and_cond_accepted = $inputs_array['terms_and_cond_accepted'];
		$driveRequest->device_id 	           = $inputs_array['device_id'];
		$driveRequest->booking_date_time       = date('Y-m-d H:i:s');
		$driveRequest->status 		           = "Requested";

        $driveRequest-> save();

        return $driveRequest;
	}


	public function updateStartDriveTime($inputs_array)
	{
          
        $driveRequest = DriveRequest::find($inputs_array['driveId']);

        $driveRequest->drive_start_time = date("Y-m-d H:i:s"); 
        $driveRequest->status           = "Started";         

        $driveRequest-> save();

        return $driveRequest;
	}


	public function updateEndDriveTime($inputs_array)
	{
	    $driveRequest = DriveRequest::find($inputs_array['driveId']);
        $driveRequest->drive_end_time    =  date("Y-m-d H:i:s");
        $driveRequest->status            = "Ended";

        $total_time_rate_charge = $this->findTotalDriveRateAmount($driveRequest);

        $driveRequest->total_travel_time = $total_time_rate_charge[0];
        $driveRequest->total_drive_rate  = $total_time_rate_charge[1];
        $driveRequest->invoice_no        = 'PDR-00000'.$driveRequest->id;

        $driveRequest-> save();

        $this->saveBillingDetails($driveRequest, $total_time_rate_charge);

        return $driveRequest;
	}



    public function findTotalDriveRateAmount($driveDetails)
    {

        try{
            
            $driveStartedHour = strtotime($driveDetails->drive_start_time);

            $driveEndedHour   = strtotime($driveDetails->drive_end_time);  

            $driveStartedDate = date('Y-m-d', strtotime($driveDetails->drive_start_time));

            $driveEndedDate   = date('Y-m-d', strtotime($driveDetails->drive_end_time));

            $peak1From  = strtotime($driveStartedDate.' 07:00:00'); 
            $peak1To    = strtotime($driveStartedDate.' 10:00:00');
            $peak2From  = strtotime($driveStartedDate.' 17:00:00'); 
            $peak2To    = strtotime($driveStartedDate.' 21:00:00');

            $normalFrom = strtotime($driveStartedDate.' 10:00:00'); 
            $normalTo   = strtotime($driveStartedDate.' 17:00:00');

            $checkStartHour = $this->isBetween("00:00", "06:59", date('H:i', strtotime($driveDetails->drive_start_time)));
            $checkEndHour   = $this->isBetween("00:00", "06:59", date('H:i', strtotime($driveDetails->drive_end_time)));
            
            if(!empty($checkStartHour) && !empty($checkEndHour) && ($driveStartedDate == $driveEndedDate) ||
               !empty($checkStartHour) && empty($checkEndHour) && ($driveStartedDate == $driveEndedDate))
            {

                $nightFrom  = strtotime(date('Y-m-d H:i:s',strtotime('-1 day', strtotime($driveStartedDate.' 21:00:00')))); 
                $nightTo    = strtotime($driveStartedDate.' 07:00:00');

            }else if(!empty($checkStartHour) && !empty($checkEndHour) && ($driveStartedDate != $driveEndedDate)){
                     
                $nightFrom  = strtotime(date('Y-m-d H:i:s',strtotime('-1 day', strtotime($driveStartedDate.' 21:00:00'))));
                $nightTo    = strtotime(date('Y-m-d H:i:s',strtotime('+1 day', strtotime($driveStartedDate.' 07:00:00'))));

            }else{

                $nightFrom  = strtotime($driveStartedDate.' 21:00:00'); 
                $nightTo    = strtotime(date('Y-m-d H:i:s',strtotime('+1 day', strtotime($driveStartedDate.' 07:00:00'))));

            }

            $nightDiff = $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour);

            if(($driveStartedHour >= $peak1From && $driveEndedHour <= $peak1To) || 
               ($driveStartedHour >= $peak2From && $driveEndedHour <= $peak2To)){
            
                return $this->calculateCharges(Config::get('pub')['hour_charge']['peak_hour'], 
                                               $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour), 
                                               Config::get('pub')['additional_hour_charge']['peak_hour']);

            }else if($driveStartedHour >= $normalFrom && $driveEndedHour <= $normalTo){

                return $this->calculateCharges(Config::get('pub')['hour_charge']['normal_hour'], 
                                               $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour),
                                               Config::get('pub')['additional_hour_charge']['normal_hour']);

            }else if($driveStartedHour >= $nightFrom && $driveEndedHour <= $nightTo && $nightDiff <= 600){

                return $this->calculateCharges(Config::get('pub')['hour_charge']['night_hour'], 
                                               $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour), 
                                               Config::get('pub')['additional_hour_charge']['night_hour']);

            }else{

                if($driveStartedHour >= $peak1From && $driveStartedHour <= $peak1To){

                       $totalMinutes = $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour);

                       $remainingHourPeakMinutes = 0; $extraMinutes = 0; $totalPeakPrice = 0; $totalExtraPrice = 0;

                        if($totalMinutes > 60)
                        {
                            $after_first_hour_drive   = $driveStartedHour + 3600;
                            if($after_first_hour_drive < $peak1To){
                                $remainingHourPeakMinutes = $this->calculateTotalMinutes($after_first_hour_drive, $peak1To);
                                $flag = 1;
                            }else{
                                $flag = 0;
                            }

                            if($remainingHourPeakMinutes == 0)
                            {

                                $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['peak_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['peak_hour']
                                                    ]
                                               ];

                                $totalPeakPrice = Config::get('pub')['hour_charge']['peak_hour'];

                            }else{

                                $billingDetails1 = [
                                                 
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['peak_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['peak_hour']
                                                    ],
                                                 
                                                    [
                                                        'price_breakup' => 'Rate after first hour peak hours', 
                                                        'quantity'      => $remainingHourPeakMinutes, 
                                                        'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                        'total_price'   => $remainingHourPeakMinutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                    ]
                                               ];

                                $totalPeakPrice = $this->calculateSum($billingDetails1);

                            }

                        }else{

                            $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['peak_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['peak_hour']
                                                    ]
                                               ];

                            $totalPeakPrice = Config::get('pub')['hour_charge']['peak_hour'];

                            return [$totalMinutes, $totalPeakPrice, $billingDetails1];
                        }
                        
                        if($driveEndedHour <= $normalTo)
                        { 
                            $normalMinutes  = $this->calculateTotalMinutes(($flag == 1)?$normalFrom:$after_first_hour_drive, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= $peak2To){
                        
                            $normalMinutes = $this->calculateTotalMinutes(($flag == 1)?$normalFrom:$after_first_hour_drive, $normalTo);
                            $peak2Minutes  = $this->calculateTotalMinutes($peak2From, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= $nightTo){
                            
                            $normalMinutes = $this->calculateTotalMinutes(($flag == 1)?$normalFrom:$after_first_hour_drive, $normalTo);
                            $peak2Minutes  = $this->calculateTotalMinutes($peak2From, $peak2To);
                            $nightMinutes  = $this->calculateTotalMinutes($nightFrom, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To)))){
                            
                            $normalMinutes = $this->calculateTotalMinutes(($flag == 1)?$normalFrom:$after_first_hour_drive, $normalTo);
                            $peak2Minutes  = $this->calculateTotalMinutes($peak2From, $peak2To);
                            $nightMinutes  = $this->calculateTotalMinutes($nightFrom, $nightTo);
                            $peak1Minutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ],
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }

                        $totalCalculatedAmount = $totalPeakPrice + $totalExtraPrice;

                        $billingDetails = array_merge($billingDetails1, $billingDetails2);

                        return [$totalMinutes, $totalCalculatedAmount, $billingDetails];

                
                }else if($driveStartedHour >= $peak2From && $driveStartedHour <= $peak2To){

                       $totalMinutes = $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour);

                       $remainingHourPeakMinutes = 0; $extraMinutes = 0; $totalPeakPrice = 0; $totalExtraPrice = 0;

                        if($totalMinutes > 60)
                        {
                            $after_first_hour_drive   = $driveStartedHour + 3600;
                            if($after_first_hour_drive < $peak2To){
                                $remainingHourPeakMinutes = $this->calculateTotalMinutes($after_first_hour_drive, $peak2To);
                                $flag = 1;
                            }else{
                                $flag = 0;
                            }

                            if($remainingHourPeakMinutes == 0)
                            {

                                $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['peak_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['peak_hour']
                                                    ]
                                               ];

                                $totalPeakPrice = Config::get('pub')['hour_charge']['peak_hour'];

                            }else{

                                $billingDetails1 = [
                                                 
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['peak_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['peak_hour']
                                                    ],
                                                 
                                                    [
                                                        'price_breakup' => 'Rate after first hour peak hours', 
                                                        'quantity'      => $remainingHourPeakMinutes, 
                                                        'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                        'total_price'   => $remainingHourPeakMinutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                    ]
                                               ];

                                $totalPeakPrice = $this->calculateSum($billingDetails1);
                            }

                        }else{

                            $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['peak_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['peak_hour']
                                                    ]
                                               ];

                            $totalPeakPrice = Config::get('pub')['hour_charge']['peak_hour'];

                            return [$totalMinutes, $totalPeakPrice, $billingDetails1];
                        }

                        if($driveEndedHour <= $nightTo)
                        { 
                            $nightMinutes  = $this->calculateTotalMinutes(($flag == 1)?$nightFrom:$after_first_hour_drive, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To)))){
                        
                            $nightMinutes = $this->calculateTotalMinutes(($flag == 1)?$nightFrom:$after_first_hour_drive, $nightTo);
                            $peak1Minutes = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalTo)))){
                            
                            $nightMinutes  = $this->calculateTotalMinutes(($flag == 1)?$nightFrom:$after_first_hour_drive, $nightTo);
                            $peak1Minutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))), strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))));
                            $normalMinutes = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalFrom))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ]
                                               ];

                                $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak2To)))){
                            
                            $nightMinutes  = $this->calculateTotalMinutes(($flag == 1)?$nightFrom:$after_first_hour_drive, $nightTo);
                            $peak1Minutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))), strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))));
                            $normalMinutes = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalFrom))), strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalTo))));
                            $peak2Minutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak2From))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ],
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                                $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }

                        $totalCalculatedAmount = $totalPeakPrice + $totalExtraPrice;

                        $billingDetails = array_merge($billingDetails1, $billingDetails2);

                        return [$totalMinutes, $totalCalculatedAmount, $billingDetails];


                }else if($driveStartedHour >= $normalFrom && $driveStartedHour <= $normalTo){

                       $totalMinutes = $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour);

                       $remainingHourNormalMinutes = 0; $extraMinutes = 0; $totalNormalPrice = 0; $totalExtraPrice = 0;

                        if($totalMinutes > 60)
                        {
                            $after_first_hour_drive   = $driveStartedHour + 3600;
                            if($after_first_hour_drive < $normalTo){
                                $remainingHourNormalMinutes = $this->calculateTotalMinutes($after_first_hour_drive, $normalTo);
                                $flag = 1;
                            }else{
                                $flag = 0;
                            }

                            if($remainingHourNormalMinutes == 0)
                            {

                                $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['normal_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['normal_hour']
                                                    ]
                                               ];

                                $totalNormalPrice = Config::get('pub')['hour_charge']['normal_hour'];

                            }else{

                                $billingDetails1 = [
                                                 
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['normal_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['normal_hour']
                                                    ],
                                                 
                                                    [
                                                        'price_breakup' => 'Rate after first hour normal hours', 
                                                        'quantity'      => $remainingHourNormalMinutes, 
                                                        'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                        'total_price'   => $remainingHourNormalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                    ]
                                               ];

                                $totalNormalPrice = $this->calculateSum($billingDetails1);

                            }

                        }else{

                            $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['normal_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['normal_hour']
                                                    ]
                                               ];

                            $totalNormalPrice = Config::get('pub')['hour_charge']['normal_hour'];

                            return [$totalMinutes, $totalNormalPrice, $billingDetails1];
                        }

                        if($driveEndedHour <= $peak2To)
                        { 
                            $peak2Minutes  = $this->calculateTotalMinutes(($flag == 1)?$peak2From:$after_first_hour_drive, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= $nightTo){
                         
                            $peak2Minutes  = $this->calculateTotalMinutes(($flag == 1)?$peak2From:$after_first_hour_drive, $peak2To);
                            $nightMinutes  = $this->calculateTotalMinutes($nightFrom, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To)))){
                          
                            $peak2Minutes  = $this->calculateTotalMinutes(($flag == 1)?$peak2From:$after_first_hour_drive, $peak2To);
                            $nightMinutes  = $this->calculateTotalMinutes($nightFrom, $nightTo);
                            $peak1Minutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalTo)))){
                          
                            $peak2Minutes  = $this->calculateTotalMinutes(($flag == 1)?$peak2From:$after_first_hour_drive, $peak2To);
                            $nightMinutes  = $this->calculateTotalMinutes($nightFrom, $nightTo);
                            $peak1Minutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))), strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))));
                            $normalMinutes = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalFrom))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],
                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }

                        $totalCalculatedAmount = $totalNormalPrice + $totalExtraPrice;

                        $billingDetails = array_merge($billingDetails1, $billingDetails2);

                        return [$totalMinutes, $totalCalculatedAmount, $billingDetails];


                }else if($driveStartedHour >= $nightFrom && $driveStartedHour <= $nightTo){


                        $totalMinutes = $this->calculateTotalMinutes($driveStartedHour, $driveEndedHour);

                        $remainingHourNightMinutes = 0; $extraMinutes = 0; $totalNightPrice = 0; $totalExtraPrice = 0;

                        if($totalMinutes > 60)
                        {
                            if(!empty($checkStartHour) && !empty($checkEndHour) && ($driveStartedDate != $driveEndedDate))
                            {
                               $nightTo  = strtotime(date('Y-m-d H:i:s',strtotime('-1 day', $nightTo)));
                            }
                     
                            $after_first_hour_drive   = $driveStartedHour + 3600;
                            if($after_first_hour_drive < $nightTo){
                                $remainingHourNightMinutes = $this->calculateTotalMinutes($after_first_hour_drive, $nightTo);
                                $flag = 1;
                            }else{
                                $flag = 0;
                            }

                            if($remainingHourNightMinutes == 0)
                            {

                                $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['night_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['night_hour']
                                                    ]
                                               ];

                                $totalNightPrice = Config::get('pub')['hour_charge']['normal_hour'];

                            }else{

                               $billingDetails1 = [
                                                 
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['night_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['night_hour']
                                                    ],
                                                 
                                                    [
                                                        'price_breakup' => 'Rate after first hour night hours', 
                                                        'quantity'      => $remainingHourNightMinutes, 
                                                        'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                        'total_price'   => $remainingHourNightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                    ]
                                               ];

                               $totalNightPrice = $this->calculateSum($billingDetails1);
                           
                            }

                        }else{

                            $billingDetails1 =  [
                                                  
                                                    [
                                                        'price_breakup' => 'First hour charge', 
                                                        'quantity'      => 1, 
                                                        'unit_price'    => Config::get('pub')['hour_charge']['night_hour'], 
                                                        'total_price'   => Config::get('pub')['hour_charge']['night_hour']
                                                    ]
                                               ];

                            $totalNightPrice = Config::get('pub')['hour_charge']['normal_hour'];

                            return [$totalMinutes, $totalNightPrice, $billingDetails1];
                        }

                        $checkPeak1  = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))):$peak1To;
                        $checkNormal = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalTo))):$normalTo;
                        $checkPeak2  = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak2To))):$peak2To;

                        if(!empty($checkStartHour) && !empty($checkEndHour) && ($driveStartedDate != $driveEndedDate) 
                            || ($driveStartedDate == $driveEndedDate) || ($driveStartedDate != $driveEndedDate))
                        {
                           $checknight2  = strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $nightTo)));
                        }else{
                           $checknight2  = $nightTo;
                        }

                        if($driveEndedHour <= $checkPeak1)
                        { 

                            $p1From = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))):$peak1From;

                            $peak1Minutes  = $this->calculateTotalMinutes(($flag == 1)?$p1From:$after_first_hour_drive, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= $checkNormal){
                            
                            $p1From = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))):$peak1From;
                            $p1To   = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))):$peak1To;
                            $nFrom  = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalFrom))):$normalFrom;

                            $peak1Minutes  = $this->calculateTotalMinutes(($flag == 1)?$p1From:$after_first_hour_drive, $p1To);
                            $normalMinutes = $this->calculateTotalMinutes($nFrom, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }else if($driveEndedHour <= $checkPeak2){

                            $p1From = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))):$peak1From;
                            $p1To   = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))):$peak1To;
                            $nFrom  = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalFrom))):$normalFrom;
                            $nTo    = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalTo))):$normalTo;
                            $p2From = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak2From))):$peak2From;

                            $peak1Minutes  = $this->calculateTotalMinutes(($flag == 1)?$p1From:$after_first_hour_drive, $p1To);
                            $normalMinutes = $this->calculateTotalMinutes($nFrom, $nTo);
                            $peak2Minutes  = $this->calculateTotalMinutes($p2From, $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);


                        }else if($driveEndedHour <=  $checknight2){

                            $p1From = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1From))):$peak1From;
                            $p1To   = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak1To))):$peak1To;
                            $nFrom  = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalFrom))):$normalFrom;
                            $nTo    = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $normalTo))):$normalTo;
                            $p2From = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak2From))):$peak2From;
                            $p2To   = empty($checkStartHour)?strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $peak2To))):$peak2To;

                            $peak1Minutes  = $this->calculateTotalMinutes(($flag == 1)?$p1From:$after_first_hour_drive, $p1To);
                            $normalMinutes = $this->calculateTotalMinutes($nFrom, $nTo);
                            $peak2Minutes  = $this->calculateTotalMinutes($p2From, $p2To);
                            $nightMinutes  = $this->calculateTotalMinutes(strtotime(date('Y-m-d H:i:s',strtotime('+1 day', $nightFrom))), $driveEndedHour);

                            $billingDetails2 = [ 
                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak1Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak1Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour normal hours', 
                                                    'quantity'      => $normalMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['normal_hour'], 
                                                    'total_price'   => $normalMinutes * Config::get('pub')['additional_hour_charge']['normal_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour peak hours', 
                                                    'quantity'      => $peak2Minutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['peak_hour'], 
                                                    'total_price'   => $peak2Minutes * Config::get('pub')['additional_hour_charge']['peak_hour']
                                                 ],

                                                 [
                                                    'price_breakup' => 'Rate after first hour night hours', 
                                                    'quantity'      => $nightMinutes, 
                                                    'unit_price'    => Config::get('pub')['additional_hour_charge']['night_hour'], 
                                                    'total_price'   => $nightMinutes * Config::get('pub')['additional_hour_charge']['night_hour']
                                                 ]
                                               ];

                            $totalExtraPrice = $this->calculateSum($billingDetails2);

                        }

                        $totalCalculatedAmount = $totalNightPrice + $totalExtraPrice;

                        $billingDetails = array_merge($billingDetails1, $billingDetails2);

                        return [$totalMinutes, $totalCalculatedAmount, $billingDetails];

                }

            }
        
        }catch(Exception $e){

            $errorMessage = [
                'status' => false,
                'message' => $e
            ];

            return $this->setStatusCode(500)->respondWithError($errorMessage);

        }

    }


    public function calculateTotalMinutes($start_time, $end_time)
    {
        try {
            
            $interval     = abs($start_time - $end_time);
            $totalMinutes = round($interval / 60);        
          
        } catch (Exception $e) {
    
            $errorMessage = [
                'status' => false,
                'message' => $e
            ];

            return $this->setStatusCode(500)->respondWithError($errorMessage);
        }

        return $totalMinutes;
    }


    public function calculateCharges($firstHourCharge, $totalMinutes, $ratePerMinutes)
    {
        try {

            $balanceMinute = 0;

            if($totalMinutes > 60){
                $balanceMinute = $totalMinutes - 60;
            }
            
            $totalCalculatedAmount = $firstHourCharge + ($balanceMinute * $ratePerMinutes);

            if($balanceMinute == 0)
            {

                $billingDetails =  [
                                     
                                        [
                                            'price_breakup' => 'First hour charge', 
                                            'quantity'      => 1, 
                                            'unit_price'    => $firstHourCharge, 
                                            'total_price'   => $firstHourCharge
                                        ]
                                   ];

            }else{

                $billingDetails = [
                                     
                                        [
                                            'price_breakup' => 'First hour charge', 
                                            'quantity'      => 1, 
                                            'unit_price'    => $firstHourCharge, 
                                            'total_price'   => $firstHourCharge
                                        ],
                                     
                                        [
                                            'price_breakup' => 'Rate after first hour', 
                                            'quantity'      => $balanceMinute, 
                                            'unit_price'    => $ratePerMinutes, 
                                            'total_price'   => $balanceMinute * $ratePerMinutes
                                        ]
                                   ];

            }
    
        } catch (Exception $e) {
    
            $errorMessage = [
                'status' => false,
                'message' => $e
            ];

            return $this->setStatusCode(500)->respondWithError($errorMessage);
        }

        return [$totalMinutes, $totalCalculatedAmount, $billingDetails];
    }


    public function isBetween($from, $till, $input) 
    {
        $f = DateTime::createFromFormat('!H:i', $from);
        $t = DateTime::createFromFormat('!H:i', $till);
        $i = DateTime::createFromFormat('!H:i', $input);
        if ($f > $t) $t->modify('+1 day');

        return ($f <= $i && $i <= $t) || ($f <= $i->modify('+1 day') && $i <= $t);
    }


    public function calculateSum($billingDetails)
    {

        $sum = 0;
        foreach ($billingDetails as $item) {
            $sum += $item['total_price'];
        }

        return $sum;
    }


    public function saveBillingDetails($driveDetails, $total_time_rate_charge)
    {

        foreach ($total_time_rate_charge[2] as $key => $value) {

            $billing = new Billing();

            $billing->drive_request_id = $driveDetails->id;
            $billing->price_breakup    = $value['price_breakup'];
            $billing->quantity         = $value['quantity'];
            $billing->unit_price       = $value['unit_price'];
            $billing->total_price      = $value['total_price'];
            
            $billing-> save();

        }

    }

}




?>