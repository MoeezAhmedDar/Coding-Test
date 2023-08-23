<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    public function __construct(Job $model, LoggerInterface $logger, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = $logger;

        $this->loggerConfigure();
    }

    private function loggerConfigure()
    {
        $this->logger->pushHandler(new StreamHandler(
            storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'),
            Logger::DEBUG
        ));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $normalJobs = array();

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobs($cuser);
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = $this->getTranslatorJobs($cuser);
                $usertype = 'translator';
            }

            if ($jobs) {
                $this->classifyJobs($jobs, $emergencyJobs, $normalJobs, $user_id);
            }
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
        ];
    }

    private function getCustomerJobs($cuser)
    {
        return $cuser->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }

    private function getTranslatorJobs($cuserId)
    {
        $jobs = Job::getTranslatorJobs($cuserId, 'new');
        return $jobs->pluck('jobs')->all();
    }

    private function classifyJobs($jobs, &$emergencyJobs, &$normalJobs, $user_id)
    {
        foreach ($jobs as $jobItem) {
            if ($jobItem->immediate === 'yes') {
                $emergencyJobs[] = $jobItem;
            } else {
                $jobItem['usercheck'] = Job::checkParticularJob($user_id, $jobItem);
                $normalJobs[] = $jobItem;
            }
        }

        usort($normalJobs, function ($a, $b) {
            return $a['due'] <=> $b['due'];
        });
    }

    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1); // Use the default value 1 if 'page' is not set
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];
        $jobs = null;

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobsHistory($cuser);
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = $this->getTranslatorJobsHistory($cuser, $page);
                $usertype = 'translator';
            }
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => $jobs ? $jobs->lastPage() : 0,
            'pagenum' => $page,
        ];
    }

    private function getCustomerJobsHistory($cuser)
    {
        return $cuser->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderByDesc('due')
            ->paginate(15);
    }

    private function getTranslatorJobsHistory($cuser, $page)
    {
        $jobs = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
        $usertype = 'translator';
        $normalJobs = $jobs;

        return [
            'data' => $normalJobs,
            'total' => $jobs->total(),
        ];
    }

    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;

        if ($user->user_type !== env('CUSTOMER_ROLE_ID')) {
            return [
                'status' => 'fail',
                'message' => "Translator can not create booking",
            ];
        }

        if (empty($data['from_language_id'])) {
            return [
                'status' => 'fail',
                'message' => "Du måste fylla in alla fält",
                'field_name' => "from_language_id",
            ];
        }

        if ($data['immediate'] === 'no') {
            if (empty($data['due_date'])) {
                return [
                    'status' => 'fail',
                    'message' => "Du måste fylla in alla fält",
                    'field_name' => "due_date",
                ];
            }

            if (empty($data['due_time'])) {
                return [
                    'status' => 'fail',
                    'message' => "Du måste fylla in alla fält",
                    'field_name' => "due_time",
                ];
            }

            if (empty($data['customer_phone_type']) && empty($data['customer_physical_type'])) {
                return [
                    'status' => 'fail',
                    'message' => "Du måste göra ett val här",
                    'field_name' => "customer_phone_type",
                ];
            }

            if (empty($data['duration'])) {
                return [
                    'status' => 'fail',
                    'message' => "Du måste fylla in alla fält",
                    'field_name' => "duration",
                ];
            }
        } elseif (empty($data['duration'])) {
            return [
                'status' => 'fail',
                'message' => "Du måste fylla in alla fält",
                'field_name' => "duration",
            ];
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] === 'yes') {
            $dueCarbon = Carbon::now()->addMinute($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            if ($dueCarbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in the past",
                ];
            }
        }

        $genderMap = [
            'male' => 'Man',
            'female' => 'Kvinna',
        ];

        $certifiedMap = [
            'normal' => 'normal',
            'certified' => 'certified',
            'certified_in_law' => 'law',
            'certified_in_helth' => 'health',
            'both' => 'both',
            'n_law' => 'n_law',
            'n_health' => 'n_health',
        ];

        if (isset($data['job_for'])) {
            $data['gender'] = $genderMap[$data['job_for'][0]] ?? null;
            $data['certified'] = $certifiedMap[$data['job_for'][1]] ?? null;
        }

        $data['job_type'] = $this->mapConsumerTypeToJobType($consumerType);
        $data['b_created_at'] = date('Y-m-d H:i:s');

        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $user->jobs()->create($data);

        return [
            'status' => 'success',
            'id' => $job->id,
            'job_for' => $data['job_for'] ?? [],
            'customer_town' => $user->userMeta->city,
            'customer_type' => $user->userMeta->customer_type,
        ];
    }

    private function mapConsumerTypeToJobType($consumerType)
    {
        $typeMap = [
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
        ];

        return $typeMap;
    }

    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id'] ?? null);

        if (isset($data['user_email'])) {
            $job->user_email = $data['user_email'];
        }

        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $this->updateJobAddressAndInstructions($job, $data);
        }

        $job->save();

        $user = $job->user()->firstOrFail();

        $recipientEmail = !empty($job->user_email) ? $job->user_email : $user->email;
        $recipientName = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $send_data = [
            'user' => $user,
            'job'  => $job
        ];

        $this->sendEmailNotification($recipientEmail, $recipientName, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);

        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    private function updateJobAddressAndInstructions($job, $data)
    {
        $job->address = !empty($data['address']) ? $data['address'] : $job->user->userMeta->address;
        $job->instructions = !empty($data['instructions']) ? $data['instructions'] : $job->user->userMeta->instructions;
        $job->town = !empty($data['town']) ? $data['town'] : $job->user->userMeta->city;
    }


    private function sendEmailNotification($email, $name, $subject, $template, $data)
    {
        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $data['due_date'] = $due_Date[0];
        $data['due_time'] = $due_Date[1];

        $data['job_for'] = $this->getJobForArray($job->gender, $job->certified);

        return $data;
    }

    private function getJobForArray($gender, $certified)
    {
        $jobFor = [];

        if ($gender != null) {
            if ($gender == 'male') {
                $jobFor[] = 'Man';
            } elseif ($gender == 'female') {
                $jobFor[] = 'Kvinna';
            }
        }

        if ($certified != null) {
            if ($certified == 'both') {
                $jobFor[] = 'Godkänd tolk';
                $jobFor[] = 'Auktoriserad';
            } elseif ($certified == 'yes') {
                $jobFor[] = 'Auktoriserad';
            } elseif ($certified == 'n_health') {
                $jobFor[] = 'Sjukvårdstolk';
            } elseif ($certified == 'law' || $certified == 'n_law') {
                $jobFor[] = 'Rätttstolk';
            } else {
                $jobFor[] = $certified;
            }
        }

        return $jobFor;
    }

    public function jobEnd($post_data = [])
    {
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        $completedDate = now();
        $dueDate = $jobDetail->due;

        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $sessionTime = $diff->format('%h tim %i min');

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $sessionTime;

        $user = $jobDetail->user()->first();
        $email = !empty($jobDetail->user_email) ? $jobDetail->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;

        $data = [
            'user' => $user,
            'job' => $jobDetail,
            'session_time' => $sessionTime,
            'for_text' => 'faktura',
        ];

        $this->sendEmail($email, $name, $subject, 'emails.session-ended', $data);

        $jobDetail->save();

        $translatorRel = $jobDetail->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

        if ($translatorRel) {
            $completedByUserId = $post_data['userid'];

            $this->fireSessionEndedEvent($jobDetail, $translatorRel, $completedByUserId, false);
            $this->fireSessionEndedEvent($jobDetail, $translatorRel, $completedByUserId, true);
        }
    }

    private function sendEmail($email, $name, $subject, $template, $data)
    {
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, $template, $data);
    }

    private function fireSessionEndedEvent($jobDetail, $translatorRel, $completedByUserId, $forInvoice)
    {
        $user = $translatorRel->user()->first();
        $email = $user->email;
        $name = $user->name;

        $forText = $forInvoice ? 'faktura' : 'lön';
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;

        $data = [
            'user' => $user,
            'job' => $jobDetail,
            'session_time' => $jobDetail->session_time,
            'for_text' => $forText,
        ];

        $this->sendEmail($email, $name, $subject, 'emails.session-ended', $data);

        $translatorRel->completed_at = now();
        $translatorRel->completed_by = $completedByUserId;
        $translatorRel->save();
    }

    public function getPotentialJobIdsWithUserId($user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;
        $jobType = $this->getJobTypeBasedOnTranslatorType($translatorType);

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $languages, $gender, $translatorLevel);

        $jobIds = $this->filterJobsByTownAndType($jobIds, $user_id);

        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    private function getJobTypeBasedOnTranslatorType($translatorType)
    {
        switch ($translatorType) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
            default:
                return 'unpaid';
        }
    }

    private function filterJobsByTownAndType($jobIds, $userId)
    {
        return collect($jobIds)->filter(function ($jobId) use ($userId) {
            $job = Job::find($jobId->id);
            $jobUserId = $job->user_id;
            $checkTown = Job::checkTowns($jobUserId, $userId);

            return !($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown;
        })->values()->all();
    }

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $translatorArray = [];
        $delayedTranslatorArray = [];

        $users = User::where('user_type', '2')->where('status', '1')->where('id', '!=', $exclude_user_id)->get();

        foreach ($users as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) {
                continue;
            }

            $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');

            if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') {
                continue;
            }

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);

            foreach ($jobs as $oneJob) {
                if ($job->id == $oneJob->id) {
                    $userId = $oneUser->id;
                    $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);

                    if ($jobForTranslator == 'SpecificJob') {
                        $jobChecker = Job::checkParticularJob($userId, $oneJob);

                        if ($jobChecker != 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($oneUser->id)) {
                                $delayedTranslatorArray[] = $oneUser;
                            } else {
                                $translatorArray[] = $oneUser;
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msgContents = '';
        if ($data['immediate'] == 'no') {
            $msgContents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msgContents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }

        $msgText = ["en" => $msgContents];

        $this->sendPushNotificationToUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToUsers($delayedTranslatorArray, $job->id, $data, $msgText, true);
    }

    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        $messageType = $this->determineMessageType($job->customer_physical_type, $job->customer_phone_type);
        $message = $messageType === 'physical' ? $physicalJobMessageTemplate : $phoneJobMessageTemplate;

        Log::info($message);

        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    private function determineMessageType($physicalType, $phoneType)
    {
        if ($physicalType == 'yes' && $phoneType == 'no') {
            return 'physical';
        } elseif ($physicalType == 'no' && $phoneType == 'yes') {
            return 'phone';
        } elseif ($physicalType == 'yes' && $phoneType == 'yes') {
            return 'phone';
        }

        return '';
    }


    public function isNeedToDelayPush($user_id)
    {
        if (DateTimeHelper::isNightTime() && TeHelper::getUsermeta($user_id, 'not_get_nighttime') !== 'yes') {
            return false;
        }

        return true;
    }

    public function isNeedToSendPush($user_id)
    {
        return TeHelper::getUsermeta($user_id, 'not_get_notification') !== 'yes';
    }

    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = (env('APP_ENV') == 'prod') ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", (env('APP_ENV') == 'prod') ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $android_sound = 'default';
        $ios_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            $android_sound = ($data['immediate'] == 'no') ? 'normal_booking' : 'emergency_booking';
            $ios_sound = ($data['immediate'] == 'no') ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    public function getPotentialTranslators(Job $job)
    {
        $translator_type = $this->getTranslatorTypeFromJobType($job->job_type);

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = $this->getTranslatorLevelsFromJob($job);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

        return $users;
    }

    private function getTranslatorTypeFromJobType($jobType)
    {
        switch ($jobType) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return '';
        }
    }

    private function getTranslatorLevelsFromJob(Job $job)
    {
        $translator_level = [];

        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        return $translator_level;
    }

    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = $this->getCurrentTranslator($job);

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job, $data['due']);
        if ($changeDue['dateChanged']) {
            $log_data[] = $changeDue['log_data'];
        }

        $langChangeResult = $this->changeLanguage($job, $data['from_language_id']);
        if ($langChangeResult['langChanged']) {
            $log_data[] = $langChangeResult['log_data'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logJobUpdate($cuser, $id, $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $changeDue['old_time']);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $data['from_language_id']);
            }
        }
    }

    private function getCurrentTranslator(Job $job)
    {
        return $job->translatorJobRel->where('cancel_at', Null)->first() ?? $job->translatorJobRel->where('completed_at', '!=', Null)->first();
    }

    private function changeLanguage(Job $job, $newLanguageId)
    {
        $langChanged = false;
        $log_data = [];

        if ($job->from_language_id != $newLanguageId) {
            $log_data = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($newLanguageId)
            ];
            $job->from_language_id = $newLanguageId;
            $langChanged = true;
        }

        return ['langChanged' => $langChanged, 'log_data' => $log_data];
    }

    private function logJobUpdate($cuser, $jobId, $logData)
    {
        $logMessage = 'USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $jobId . '">#' . $jobId . '</a> with data: ';
        $this->logger->addInfo($logMessage, $logData);
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $statusChanged = false;

        if ($oldStatus != $data['status']) {
            $statusHandlers = [
                'timedout' => 'changeTimedoutStatus',
                'completed' => 'changeCompletedStatus',
                'started' => 'changeStartedStatus',
                'pending' => 'changePendingStatus',
                'withdrawafter24' => 'changeWithdrawafter24Status',
                'assigned' => 'changeAssignedStatus',
            ];

            $statusHandler = $statusHandlers[$job->status] ?? null;

            if ($statusHandler && method_exists($this, $statusHandler)) {
                $statusChanged = call_user_func([$this, $statusHandler], $job, $data, $changedTranslator);

                if ($statusChanged) {
                    $logData = [
                        'old_status' => $oldStatus,
                        'new_status' => $data['status'],
                    ];

                    return ['statusChanged' => true, 'log_data' => $logData];
                }
            }
        }

        return ['statusChanged' => $statusChanged, 'log_data' => []];
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $jobData = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $jobData, '*');   // send Push all suitable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    private function changeCompletedStatus($job, $data)
    {
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->status = $data['status'];
        $job->save();

        return true;
    }


    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] === '') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] === 'completed') {
            if ($data['session_time'] === '') {
                return false;
            }

            $interval = $data['session_time'];
            $diff = explode(':', $interval);

            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $user = $job->user()->first();

            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }

            $name = $user->name;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

            if ($translator !== null) {
                $email = $translator->user->email;
                $name = $translator->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $dataEmail = [
                    'user'         => $translator->user,
                    'job'          => $job,
                    'session_time' => $session_time,
                    'for_text'     => 'lön'
                ];
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            }
        }

        $job->save();
        return true;
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['status'] === 'timedout' && $data['admin_comments'] === '') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        $user = $job->user()->first();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }

        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } elseif ($data['status'] !== 'assigned') {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }

        return false;
    }

    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->configureLogger();

        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $dueDateTime = new DateTime($due);
        $dueDate = $dueDateTime->format('Y-m-d');
        $dueTime = $dueDateTime->format('H:i');

        $locationMessage = ($job->customer_physical_type == 'yes') ? 'på plats i ' . $job->town : 'telefon';

        $msg_text = [
            'en' => "Detta är en påminnelse om att du har en $language tolkning ($locationMessage) kl $dueTime på $dueDate som varar i $duration min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!",
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $isNeedDelay = $this->bookingRepository->isNeedToDelayPush($user->id);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $isNeedDelay);
            $this->logger->addInfo('sendSessionStartRemindNotification', ['job' => $job->id]);
        }
    }

    private function configureLogger()
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }


    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }
        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $this->sendStatusChangedEmails($job);

                $job->save();
                return true;
            }
        }

        return false;
    }

    private function sendStatusChangedEmails($job)
    {
        $customer = $job->user;
        $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first()->user;

        if (empty($job->user_email)) {
            $customerEmail = $customer->email;
        } else {
            $customerEmail = $job->user_email;
        }

        $customerData = [
            'user' => $customer,
            'job' => $job,
        ];

        $translatorData = [
            'user' => $translator,
            'job' => $job,
        ];

        $this->sendEmailToUser($customerEmail, $customer->name, 'Customer', $job, 'emails.status-changed-from-pending-or-assigned-customer', $customerData);
        $this->sendEmailToUser($translator->email, $translator->name, 'Translator', $job, 'emails.job-cancel-translator', $translatorData);
    }

    private function sendEmailToUser($email, $name, $type, $job, $template, $data)
    {
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        if (!is_null($current_translator) || ($this->isNewTranslatorDataProvided($data))) {
            if (!is_null($current_translator) && $this->isTranslatorDataChanged($data, $current_translator)) {
                $new_translator = $this->createNewTranslatorFromCurrent($data, $current_translator);
                $this->cancelCurrentTranslator($current_translator);
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (!$this->isTranslatorDataEmpty($data)) {
                $new_translator = $this->createNewTranslatorFromData($data, $job);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }

            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    private function isNewTranslatorDataProvided($data)
    {
        return isset($data['translator']) && ($data['translator'] != 0 || !empty($data['translator_email']));
    }

    private function isTranslatorDataChanged($data, $current_translator)
    {
        return (isset($data['translator']) && $data['translator'] != $current_translator->user_id) || !empty($data['translator_email']);
    }

    private function createNewTranslatorFromCurrent($data, $current_translator)
    {
        if (!empty($data['translator_email'])) {
            $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        $new_translator = $current_translator->toArray();
        $new_translator['user_id'] = $data['translator'];
        unset($new_translator['id']);
        return Translator::create($new_translator);
    }

    private function cancelCurrentTranslator($current_translator)
    {
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();
    }

    private function isTranslatorDataEmpty($data)
    {
        return empty($data['translator']) && empty($data['translator_email']);
    }

    private function createNewTranslatorFromData($data, $job)
    {
        if (!empty($data['translator_email'])) {
            $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        return Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
    }

    private function changeDue($old_due, $new_due)
    {
        $dateChanged = $old_due != $new_due;

        if ($dateChanged) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];

            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $jobId = $job->id;
        $emailSubject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $jobId;

        $userData = [
            'user' => $user,
            'job' => $job
        ];

        $this->sendEmailNotification($user, '', $emailSubject, 'emails.job-changed-translator-customer', $userData);

        if ($current_translator) {
            $currentTranslatorUser = $current_translator->user;
            $this->sendEmailNotification($currentTranslatorUser, '', $emailSubject, 'emails.job-changed-translator-old-translator', $userData);
        }

        $newTranslatorUser = $new_translator->user;
        $this->sendEmailNotification($newTranslatorUser, '', $emailSubject, 'emails.job-changed-translator-new-translator', $userData);
    }

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $emailSubject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $userData = [
            'user' => $user,
            'job' => $job,
            'old_time' => $old_time
        ];

        $this->sendEmailNotification($user, '', $emailSubject, 'emails.job-changed-date', $userData);
        $this->sendEmailNotification($translator, '', $emailSubject, 'emails.job-changed-date', $userData);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $emailSubject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $userData = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $old_lang
        ];

        $this->sendEmailNotification($user, '', $emailSubject, 'emails.job-changed-lang', $userData);
        $this->sendEmailNotification($translator, '', $emailSubject, 'emails.job-changed-date', $userData);
    }

    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $notificationType = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $userMeta = $job->user->userMeta()->first();

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $userMeta->city,
            'customer_type' => $userMeta->customer_type,
        ];

        [$dueDate, $dueTime] = explode(" ", $job->due);
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueTime;

        $data['job_for'] = [];

        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        if ($job->customer_physical_type == 'yes') {
            $msg_text = [
                'en' => "Du har nu fått platstolkningen för $language kl $duration den $due. Vänligen säkerställ att du är förberedd för den tiden. Tack!",
            ];
        } else {
            $msg_text = [
                'en' => "Du har nu fått telefontolkningen för $language kl $duration den $due. Vänligen säkerställ att du är förberedd för den tiden. Tack!",
            ];
        }

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

    private function getUserTagsStringFromArray($users)
    {
        $userTags = [];

        foreach ($users as $oneUser) {
            $userTags[] = [
                'operator' => 'OR',
                'key' => 'email',
                'relation' => '=',
                'value' => strtolower($oneUser->email),
            ];
        }

        return json_encode($userTags);
    }

    public function acceptJob($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);

        if (!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->first();
                $this->sendAcceptanceConfirmationEmail($job, $user);

                $jobs = $this->getPotentialJobs($user);
                return [
                    'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                    'status' => 'success',
                ];
            }

            return [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.',
            ];
        }

        return [
            'status' => 'fail',
            'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.',
        ];
    }

    private function sendAcceptanceConfirmationEmail($job, $user)
    {
        $mailer = new AppMailer();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job' => $job,
        ];
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

    public function acceptJobWithId($jobId, $cuser)
    {
        $job = Job::findOrFail($jobId);
        $response = [];

        if (Job::isTranslatorAlreadyBooked($jobId, $cuser->id, $job->due)) {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
            return $response;
        }

        if ($job->status !== 'pending' || !Job::insertTranslatorJobRel($cuser->id, $jobId)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $response['status'] = 'fail';
            $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            return $response;
        }

        $job->status = 'assigned';
        $job->save();
        $user = $job->user()->first();
        $this->sendAcceptanceConfirmationEmail($job, $user);

        $response = $this->sendJobAcceptedNotification($user, $job);
        $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;

        return $response;
    }

    private function sendJobAcceptedNotification($user, $job)
    {
        $data = [
            'notification_type' => 'job_accepted',
        ];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            'en' => "Din bokning för $language translators, $job->duration min, $job->due har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.",
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }

        return [
            'status' => 'success',
            'list'   => ['job' => $job],
        ];
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $response = $this->cancelCustomerJob($job, $translator);
        } else {
            $response = $this->cancelTranslatorJob($job, $translator);
        }

        return $response;
    }

    private function cancelCustomerJob($job, $translator)
    {
        $response = [];

        $job->withdraw_at = Carbon::now();
        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }

        $job->save();
        Event::fire(new JobWasCanceled($job));

        $response['status'] = 'success';
        $response['jobstatus'] = 'success';

        if ($translator) {
            $this->sendJobCancelledNotification($translator, $job);
        }

        return $response;
    }

    private function cancelTranslatorJob($job, $translator)
    {
        $response = [];

        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $customer = $job->user()->get()->first();
            if ($customer) {
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job->id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);

                $response['status'] = 'success';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
        }

        return $response;
    }

    private function sendJobCancelledNotification($translator, $job)
    {
        $data = [];
        $data['notification_type'] = 'job_cancelled';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => "Kunden har avbokat bokningen för $language tolk, $job->duration min, $job->due. Var god och kolla dina tidigare bokningar för detaljer.",
        ];

        if ($this->isNeedToSendPush($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
        }
    }

    public function getPotentialJobs($cuser)
    {
        $cuserMeta = $cuser->userMeta;
        $jobType = $this->determineJobType($cuserMeta->translator_type);
        $userLanguages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;

        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);

        foreach ($jobIds as $key => $job) {
            $jobUserId = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checkTown = Job::checkTowns($jobUserId, $cuser->id);

            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                unset($jobIds[$key]);
            }

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && $job->customer_physical_type === 'yes' && !$checkTown) {
                unset($jobIds[$key]);
            }
        }

        return $jobIds;
    }

    private function determineJobType($translatorType)
    {
        $jobType = 'unpaid';

        if ($translatorType === 'professional') {
            $jobType = 'paid';
        } elseif ($translatorType === 'rwstranslator') {
            $jobType = 'rws';
        } elseif ($translatorType === 'volunteer') {
            $jobType = 'unpaid';
        }

        return $jobType;
    }

    public function endJob($postData)
    {
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if ($jobDetail->status !== 'started') {
            return ['status' => 'success'];
        }

        $completedDate = now();
        $dueDate = $jobDetail->due;

        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h tim %i min');

        $job = $jobDetail;
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $interval,
            'for_text'     => 'faktura',
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $translatorRel = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();

        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $translatorRel->user_id : $job->user_id));

        $translatorUser = $translatorRel->user()->first();
        $translatorEmail = $translatorUser->email;
        $translatorName = $translatorUser->name;

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user'         => $translatorUser,
            'job'          => $job,
            'session_time' => $interval,
            'for_text'     => 'lön',
        ];

        $mailer->send($translatorEmail, $translatorName, $subject, 'emails.session-ended', $data);

        $translatorRel->completed_at = $completedDate;
        $translatorRel->completed_by = $postData['user_id'];
        $translatorRel->save();

        return ['status' => 'success'];
    }

    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $authenticatedUser = $request->__authenticatedUser;
        $consumerType = $authenticatedUser->consumer_type;

        $query = Job::query();

        if ($authenticatedUser && $authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            // Superadmin-specific filters
            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $query->where('ignore_feedback', 0)
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', 3);
                    });
                if (isset($requestData['count']) && $requestData['count'] != 'false') {
                    return ['count' => $query->count()];
                }
            }

            // Other filters for superadmins
            if (isset($requestData['id']) && $requestData['id'] != '') {
                $query->whereIn('id', is_array($requestData['id']) ? $requestData['id'] : [$requestData['id']]);
                $requestData = array_only($requestData, ['id']);
            }

            // Apply various filters...
            // ...

            $query->orderBy('created_at', 'desc');
            $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        } else {
            // Filters for regular users (non-superadmins)
            $query->where('job_type', '=', $consumerType == 'RWS' ? 'rws' : 'unpaid');

            // Apply feedback filter if needed...
            // ...

            // Other filters for regular users
            if (isset($requestData['id']) && $requestData['id'] != '') {
                $query->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            // Apply various filters...
            // ...

            $query->orderBy('created_at', 'desc');
            $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        }

        // Pagination logic
        if ($limit == 'all') {
            $jobs = $query->get();
        } else {
            $jobs = $query->paginate(15);
        }

        return $jobs;
    }

    public function alerts()
    {
        $jobIds = [];
        $jobDurations = [];

        // Extract job IDs and calculate session durations
        $jobs = Job::all();
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $duration = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                $jobDurations[$job->id] = $duration;

                if ($duration >= $job->duration && $duration >= $job->duration * 2) {
                    $jobIds[] = $job->id;
                }
            }
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $cuser = Auth::user();
        $consumerType = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $query = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobIds)
                ->where('jobs.ignore', 0);

            // Apply filters based on request data
            // ...

            $query->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc');

            $allJobs = $query->paginate(15);
        }

        $allCustomers = DB::table('users')->where('user_type', '1')->pluck('email')->all();
        $allTranslators = DB::table('users')->where('user_type', '2')->pluck('email')->all();

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $allCustomers,
            'all_translators' => $allTranslators,
            'requestdata' => $requestData,
        ];
    }


    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $requestdata = Request::all();
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        // Only superadmins and admins can access this function
        if (!$cuser || (!$cuser->is('superadmin') && !$cuser->is('admin'))) {
            return abort(403, 'Unauthorized');
        }

        $query = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.status', 'pending')
            ->where('jobs.due', '>=', Carbon::now());

        // Apply filters based on request data
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $query->whereIn('jobs.from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $query->whereIn('jobs.status', $requestdata['status']);
        }
        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $query->where('jobs.user_id', $user->id);
            }
        }
        if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
            if ($user) {
                $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                $query->whereIn('jobs.id', $allJobIDs);
            }
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('jobs.created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('jobs.created_at', '<=', $to);
            }
            $query->orderBy('jobs.created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('jobs.due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('jobs.due', '<=', $to);
            }
            $query->orderBy('jobs.due', 'desc');
        }
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $query->whereIn('jobs.job_type', $requestdata['job_type']);
        }

        $query->select('jobs.*', 'languages.language');
        $query->orderBy('jobs.created_at', 'desc');

        $allJobs = $query->paginate(15);

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email')->all();
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email')->all();

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata,
        ];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid)->toArray();

        $data = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
            'updated_at' => now(),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => now(),
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
        ];

        if ($job['status'] != 'timedout') {
            Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job = array_merge($job, [
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
                'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobid,
            ]);

            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        } else {
            $hours = floor($time / 60);
            $minutes = ($time % 60);

            return sprintf($format, $hours, $minutes);
        }
    }
}
