<?php 

    CONST DOMAIN = "reliancehmo.com";

    

    public function login() {
        $body = file_get_contents("php://input");
        if ($body == NULL) {
            return response()->json(['status' => 'error', 'message' => 'No body found in request'], 400, $this->headers);
        }
        self::$validator($body);// validate inputs
        
        $body = json_decode($body);
        $data = [];
        $envr = App::environment('staging') ? 'testing.' : '';
        $username = trim($body->username);
        $password = $body->password;
        $use_hmo_id = false;
        $user = NULL;
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $user = User::getUserByEmail(strtolower($username));
        } elseif (ApiUtility::isHmoIdFormat($username)) {
            $enrolleeProfile = EnrolleeProfile::getEnrolleeProfileByHmoId($username);
            if (!$enrolleeProfile) {
                return response()->json(['status' => 'error', 'message' => 'HMO ID doesn\'t exist'], 401, $this->headers);
            }
            $user = $enrolleeProfile->user;
            $use_hmo_id = true;
        }
        if (!$user) { 
            return response()->json(['status' => 'error', 'message' => $use_hmo_id ? 'User Profile doesn\'t exist' : 'Email doesn\'t exist'], 401, $this->headers);
        }
        if (!User::validatePassword($user->email_address, $password)) {
            return response()->json(['status' => 'error', 'message' => 'Incorrect Credentials'], 401, $this->headers);
        }
        $user_info = [];
        $user_roles = [];
        $phone_number = "";
        $email_address = $user->email_address;
        //format phone number
        if ($user->phone_number){
            $phone_number = ApiUtility::phoneNumberFromDBFormat($user->phone_number);
        }
        //format email
        if ($user->duplicate_email_address){
            $email_address = $user->duplicate_email_address;
        }
        $user_info['id'] = $user->id;
        $user_info['first_name'] = $user->first_name;
        $user_info['last_name'] = $user->last_name;
        $user_info['email_address'] = $email_address;
        $user_info['phone_number'] = $phone_number;
        $user_info['referral_code'] = $user->referral_code;
        $user_info['access_token'] = $user->access_token;
        //ACCOUNT_OWNER - ACCOUNTS Access
        $accountManagerRoles = [Role::ACCOUNT_OWNER];
        $has_role = false;
        foreach ($accountManagerRoles as $each) {
            if (UserToRole::userHasSpecificRole($user->id, $each)) {
                $has_role = true;
                break;
            }
        }
        $one = [];
        $one['name'] = "user";
        $one['display_name'] = "Manage Accounts";
        $one['can_access'] = $has_role;
        $one['url'] = "https://accounts." . ApiUtility::domainByEnvironment() . DOMAIN;
        array_push($user_roles, $one);
        //Enrollee Access 
        $enrolleeRoles = [Role::ENROLLEE, Role::DEPENDANT];
        $has_role = false;
        foreach ($enrolleeRoles as $each) {
            if (UserToRole::userHasSpecificRole($user->id, $each)) {
                $has_role = true;
                break;
            }
        }
        $one = [];
        $one['name'] = "enrollee";
        $one['display_name'] = "RelianceCare";
        $one['can_access'] = $has_role;
        $one['url'] = "https://dashboard." . ApiUtility::domainByEnvironment() . DOMAIN;
        array_push($user_roles, $one);
        if (!$use_hmo_id) {
            //Client access
            $clientAdminRoles = [Role::CLIENT_ADMINISTRATOR];
            $has_role = false;
            foreach ($clientAdminRoles as $each) {
                if (UserToRole::userHasSpecificRole($user->id, $each)) {
                    $has_role = true;
                    break;
                }
            }
            $one = [];
            $one['name'] = "client";
            $one['display_name'] = "Company Dashboard";
            $one['can_access'] = $has_role;
            $one['url'] = "https://client." . ApiUtility::domainByEnvironment() . DOMAIN;
            array_push($user_roles, $one);
            //Provider Access
            $providerRoles = [Role::PROVIDER_MEDICAL_DIRECTOR, Role::HMO_MANAGER, Role::BILLING_OFFICER, Role::FRONTDESK_OFFICER];
            $has_role = false;
            foreach ($providerRoles as $each) {
                if (UserToRole::userHasSpecificRole($user->id, $each)) {
                    $has_role = true;
                    break;
                }
            }
            $one = [];
            $one['name'] = "provider";
            $one['display_name'] = "Hospital Dashboard";
            $one['can_access'] = $has_role;
            $one['url'] = "https://provider." . ApiUtility::domainByEnvironment('old') . DOMAIN;
            array_push($user_roles, $one);
        }
        // affiliate role
        $has_role = false;
        if (UserToRole::userHasSpecificRole($user->id, Role::AFFILIATE)) {
            $has_role = true;
        }
        $user_roles[] = [
            'name' => 'affiliate',
            'display_name' => 'Affiliate Dashboard',
            'can_access' => $has_role,
            'url' => "https://affiliates." . ($envr == 'testing.' ? 'staging.' : $envr) . DOMAIN,
        ];
        // doctor role
        $has_role = false;
        if (UserToRole::userHasSpecificRole($user->id, Role::DOCTOR)) {
            $has_role = true;
        }
        // add doctor role if doctor profile exists and is active
        $doctorProfile = DoctorProfile::getDoctorProfileByUserId($user->id);
        if ($doctorProfile && $doctorProfile->active_status == ActiveStatus::ACTIVE) {
            $user_roles[] = [
                'name' => 'doctor',
                'display_name' => 'Doctor Dashboard',
                'can_access' => $has_role,
                'url' => "https://telemedicine." . ($envr == 'testing.' ? 'staging.' : $envr) . DOMAIN,
            ];
        }
        // partnership_agent role
        $has_role = false;
        if (UserToRole::userHasSpecificRole($user->id, Role::PARTNERSHIP_AGENT)) {
            $has_role = true;
        }
        $user_roles[] = [
            'name' => 'partnership_agent',
            'display_name' => 'Partnership Agent Dashboard',
            'can_access' => $has_role,
            'url' => "https://partners." . ($envr == 'testing.' ? 'staging.' : $envr) . DOMAIN,
        ];
        //Logs in Login Table
        $login = new Login();
        $login->user_id = $user->id;
        $login->source_id = Source::WEB_APP;
        $login->save();
        $data = [
            'basic_info' => $user_info,
            'roles' => $user_roles
        ];
        return response()->json(['status' => 'success', 'data' =>  $data], 200, $this->headers);
    }

    private static function validateInputs($body)
    {
        $validator = Validator::make(json_decode($body,true), [
            "username" => "required|string",
            "password" => "required|string",
        ], [
            'username.required' => 'Kindly provide your email address or hmo id.',
            'password.required' => 'Kindly provide your password.',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' =>  $validator->errors()->first()], 400, $this->headers);
        }
    }
?>