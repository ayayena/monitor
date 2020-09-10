<?php

namespace App\Http\Controllers;

use http\Message;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use App\SuspectCase;
use App\Patient;
use App\Ventilator;
use App\File;
use App\EstablishmentUser;
use App\Tracing\Event;
use App\Tracing\EventType;
use App\SanitaryResidence\Residence;
use App\SanitaryResidence\Booking;
use Carbon\Carbon;
use App\Lab\Exam\SARSCoV2External;
use App\Laboratory;
use App\Region;
use App\WSMinsal;
use App\Commune;
use App\Country;
use Illuminate\Support\Str;
use Illuminate\View\View;

use App\User;

class SuspectCaseReportController extends Controller
{
    public function positives()
    {
        // set_time_limit(3600);

        /* Obtiene comunas .env */
        $communes_ids = array_map('trim', explode(",", env('COMUNAS')));
        $communes = Commune::whereIn('id', $communes_ids)->get();

        /* Consulta base para las demás consultas de pacientes*/
        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereHas('demographic', function ($q) use ($communes_ids) {
            $q->whereIn('commune_id', $communes_ids);
        });

        /* Valida que existan casos positivos */
        if ($patients->count() == 0) {
            session()->flash('info', 'No existen casos positivos o no hay casos con dirección.');
            return redirect()->route('home');
        }

        /* Total casos */
        $casosTotalesArray = $this->getTotalPatients($patients);

        /* Evolución */
        $evolucion = $this->getEvolucion($communes_ids);

        /* Exámenes */
        $exams['total'] = SuspectCase::count();
        $exams['positives'] = SuspectCase::where('pcr_sars_cov_2', 'positive')->count();
        $exams['negatives'] = SuspectCase::where('pcr_sars_cov_2', 'negative')->count();
        $exams['pending'] = SuspectCase::where('pcr_sars_cov_2', 'pending')->count();
        $exams['undetermined'] = SuspectCase::where('pcr_sars_cov_2', 'undetermined')->count();
        $exams['rejected'] = SuspectCase::where('pcr_sars_cov_2', 'rejected')->count();

        /* Ventiladores */
        list($ventilator, $UciPatients) = $this->getVentilatorStats($patients);

        /* Fallecidos */
        $totalDeceasedArray = $this->getDeceasedPatients($patients);

        /* Pacientes por rango edades */
        $ageRangeArray = $this->getRangeArray($patients);

        /* Casos por comuna */
        $casesByCommuneArray = $this->getCasesByCommune($communes);

        return view('lab.suspect_cases.reports.positives', compact('evolucion', 'ventilator', 'exams', 'communes', 'ageRangeArray', 'casosTotalesArray', 'totalDeceasedArray', 'casesByCommuneArray', 'UciPatients'));
    }

    /**
     * Obtiene positivos y acumulados por día.
     * @return Application|Factory|View
     */
    public function positivesOwn()
    {
        $communes_ids = Auth::user()->communes();
        $communes = Commune::find(Auth::user()->communes());

        /* Consulta base para las demás consultas de pacientes */
        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereHas('demographic', function ($q) {
            $q->whereIn('commune_id', Auth::user()->communes());
        });

        $totalPatients = $this->getTotalPatientsOwn($patients);

        /* Evolución */
        $evolucion = $this->getEvolucionOwn($communes_ids);

        /* cálculo de positivos */
        $from = Carbon::now()->subDays(30);
        $to = Carbon::now();

        $suspectcases = SuspectCase::select('pcr_sars_cov_2_at')
            ->where('pcr_sars_cov_2', 'positive')
            ->whereBetween('pcr_sars_cov_2_at', [$from, $to])
            ->orderByDesc('sample_at')
            ->get();

        foreach ($suspectcases as $key => $suspectcase) {
            $positives[$suspectcase->pcr_sars_cov_2_at->format('d-m-Y')] = 0;
        }

        foreach ($suspectcases as $key => $suspectcase) {
            $positives[$suspectcase->pcr_sars_cov_2_at->format('d-m-Y')] += 1;
        }

        /* Fallecidos */
        $totalDeceasedArray = $this->getDeceasedPatients($patients);

        /*Se calcula número pacientes por rango edades */
        $ageRangeArray = $this->getRangeArray($patients);

        /* Casos por comuna */
        $casesByCommuneArray = $this->getCasesByCommuneByGender($communes);

        return view('lab.suspect_cases.reports.positives_own', compact('evolucion', 'communes', 'positives', 'ageRangeArray', 'totalPatients', 'totalDeceasedArray', 'casesByCommuneArray'));
    }

    /**
     * @return int
     */
    public function getTotalPatientsOwn($patients): int
    {
        $totalPatients = $patients->count();
        return $totalPatients;
    }

    /**
     * @param array $communes_ids
     * @param $patients
     * @return array
     */
    public function getRangeArray($patients): array
    {
        $ageRangeArray = array();
        for ($i = 10; $i <= 90; $i += 10) {

            $patients1 = clone $patients;
            $patients2 = clone $patients;

            $malePatients = $patients1->where('gender', 'male');
            $femalePatients = $patients2->where('gender', 'female');

            $subYearsBegin = $i . ' years';
            $subYearsEnd = $i - 10 . ' years';

            if ($i == 90) $subYearsBegin = $i + 60 . ' years';

            $begin = Carbon::now()->sub($subYearsBegin);
            $end = Carbon::now()->sub($subYearsEnd);

            $cantMale = $malePatients->whereBetween('birthday', [$begin, $end])->count();
            $cantFemale = $femalePatients->whereBetween('birthday', [$begin, $end])->count();

            array_push(
                $ageRangeArray,
                array(
                    'male' => $cantMale,
                    'female' => $cantFemale
                )
            );
        }

        $patients3 = clone $patients;
        $birthdayNullPatients = $patients3->whereNull('birthday')->count();

        array_push(
            $ageRangeArray,
            array('null' => $birthdayNullPatients)
        );

        return $ageRangeArray;
    }

    /**
     * @param array $communes_ids
     */
    public function getEvolucion($communes_ids)
    {
        $begin = SuspectCase::where('pcr_sars_cov_2', 'positive')
            ->orderBy('sample_at')
            ->first()->sample_at->startOfDay();
        $end = SuspectCase::where('pcr_sars_cov_2', 'positive')
            ->orderByDesc('sample_at')
            ->first()->sample_at->endOfDay();

        $days = array();
        for ($i = $begin; $i <= $end; $i->modify('+1 day')) {
            $days[$i->format("Y-m-d")] = 0;
        }

        $patients = Patient::has('firstPositive')
            ->select('id')
            ->whereHas('demographic', function ($q) use ($communes_ids) {
                $q->whereIn('commune_id', $communes_ids);
            })
            ->addSelect(['sample_at' => SuspectCase::selectRaw('DATE(sample_at) as sample_at')
                ->whereColumn('patient_id', 'patients.id')
                ->where('pcr_sars_cov_2', 'positive')
                ->take(1)])
            ->get();

        foreach ($patients as $patient) {
            $days[$patient->sample_at] += 1;
        }

        $acumulado = 0;
        foreach ($days as $day => $total) {
            $acumulado += $total;
            $evolucion[$day] = $acumulado;
        }
        return $evolucion;
    }

    /**
     * @param array $communes_ids
     */
    public function getEvolucionOwn($communes_ids)
    {
        $patients = Patient::has('firstPositive')
            ->select('id')
            ->whereHas('demographic', function ($q) use ($communes_ids) {
                $q->whereIn('commune_id', $communes_ids);
            })
            ->addSelect(['sample_at' => SuspectCase::selectRaw('DATE(sample_at) as sample_at')
                ->whereColumn('patient_id', 'patients.id')
                ->where('pcr_sars_cov_2', 'positive')
                ->take(1)])
            ->get();

        $begin = SuspectCase::where('pcr_sars_cov_2', 'positive')
            ->whereIn('patient_id', $patients->pluck('id')->toArray())
            ->orderBy('sample_at')
            ->first()->sample_at->startOfDay();
        $end = SuspectCase::where('pcr_sars_cov_2', 'positive')
            ->whereIn('patient_id', $patients->pluck('id')->toArray())
            ->orderByDesc('sample_at')
            ->first()->sample_at->endOfDay();

        $days = array();
        for ($i = $begin; $i <= $end; $i->modify('+1 day')) {
            $days[$i->format("Y-m-d")] = 0;
        }

        foreach ($patients as $patient) {
            $days[$patient->sample_at] += 1;
        }

        $acumulado = 0;
        foreach ($days as $day => $total) {
            $acumulado += $total;
            $evolucion[$day] = $acumulado;
        }
        return $evolucion;
    }

    /**
     * @param array $communes_ids
     * @param $patientBase
     * @return array
     */
    public function getTotalPatients($patients): array
    {
        $patients1 = clone $patients;
        $patients2 = clone $patients;
        $patientsMale = $patients1->where('gender', 'male')->count();
        $patientsFemale = $patients2->where('gender', 'female')->count();

        $casosTotalesArray = array();
        $casosTotalesArray['male'] = $patientsMale;
        $casosTotalesArray['female'] = $patientsFemale;

        return $casosTotalesArray;
    }

    /**
     * @param array $communes_ids
     * @param $patients
     * @return array
     */
    public function getDeceasedPatients($patients): array
    {
        $patients1 = clone $patients;
        $patients2 = clone $patients;

        $malePatients = $patients1->where('gender', 'male')
            ->where('status', 'Fallecido')->count();

        $femalePatients = $patients2->where('gender', 'female')
            ->where('status', 'Fallecido')->count();

        $totalDeceasedArray = array();
        $totalDeceasedArray['male'] = $malePatients;
        $totalDeceasedArray['female'] = $femalePatients;
        return $totalDeceasedArray;
    }

    /**
     * @param $communes
     * @return array
     */
    public function getCasesByCommune($communes): array
    {
        $casesByCommuneArray = array();
        foreach ($communes as $commune) {
            $cant = Patient::whereHas('suspectCases', function ($q) {
                $q->where('pcr_sars_cov_2', 'positive');
            })->whereHas('demographic', function ($q) use ($commune) {
                $q->where('commune_id', $commune->id);
            })->count();

            $casesByCommuneArray[$commune->id] = $cant;
        }

        $cant = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereHas('demographic', function ($q) use ($commune) {
            $q->where('commune_id', null);
        })->count();
        $casesByCommuneArray['Sin Registro'] = $cant;
        return $casesByCommuneArray;
    }

    /**
     * @param $communes
     * @return array
     */
    public function getCasesByCommuneByGender($communes): array
    {
        $casesByCommuneArray = array();
        foreach ($communes as $commune) {
            $cantMale = Patient::whereHas('suspectCases', function ($q) {
                $q->where('pcr_sars_cov_2', 'positive');
            })->whereHas('demographic', function ($q) use ($commune) {
                $q->where('commune_id', $commune->id);
            })->where('gender', 'male')
                ->count();

            $cantFemale = Patient::whereHas('suspectCases', function ($q) {
                $q->where('pcr_sars_cov_2', 'positive');
            })->whereHas('demographic', function ($q) use ($commune) {
                $q->where('commune_id', $commune->id);
            })->where('gender', 'female')
                ->count();

            $casesByCommuneArray[$commune->id]['male'] = $cantMale;
            $casesByCommuneArray[$commune->id]['female'] = $cantFemale;
        }

        return $casesByCommuneArray;
    }

    /**
     * @param array $communes_ids
     * @param $param
     * @return array
     */
    public function getVentilatorStats($patients): array
    {
        $patients1 = clone $patients;
        $ventilator = Ventilator::first();

        $UciPatients = $patients1->where('status', 'Hospitalizado UCI (Ventilador)')->count();
        return array($ventilator, $UciPatients);
    }

    /*****************************************************/
    /*                 SEGUIMIENTO CASOS                 */
    /*****************************************************/
    public function case_tracing(Request $request)
    {

        $env_communes = array_map('trim', explode(",", env('COMUNAS')));

        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereHas('demographic', function ($q) use ($env_communes) {
            $q->whereIn('commune_id', $env_communes);
        })
            ->with('inmunoTests')
            ->latest()
            ->paginate(500);

        $patientsNoDemographic = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->doesntHave('demographic')
            ->with('inmunoTests')
            ->get();

        $max_cases = 0;
        $max_cases_inmuno = 0;
        foreach ($patients as $patient) {
            if ($max_cases < $patient->suspectCases->count()) {
                $max_cases = $patient->suspectCases->count();
            }
            if ($max_cases_inmuno < $patient->inmunoTests->count()) {
                $max_cases_inmuno = $patient->inmunoTests->count();
            }
        }

        $max_cases_no_demographic = 0;
        $max_cases_inmuno_no_demographic = 0;
        foreach ($patientsNoDemographic as $patient) {
            if ($max_cases_no_demographic < $patient->suspectCases->count()) {
                $max_cases_no_demographic = $patient->suspectCases->count();
            }
            if ($max_cases_inmuno_no_demographic < $patient->inmunoTests->count()) {
                $max_cases_inmuno_no_demographic = $patient->inmunoTests->count();
            }
        }

        return view('lab.suspect_cases.reports.case_tracing', compact('patients', 'max_cases', 'max_cases_inmuno', 'patientsNoDemographic', 'max_cases_no_demographic', 'max_cases_inmuno_no_demographic'));
    }


    public function ownIndexFilter(request $request, Laboratory $laboratory)
    {

        $laboratories = Laboratory::All();
        //$laboratory = Laboratory::All();
        if ($from = $request->has('from')) {
            $from = $request->get('from');
            $to = $request->get('to');
        } else {
            $from = date("Y-m-d 21:00", time() - 60 * 60 * 24);
            $to = date("Y-m-d 20:59");
        }

        if($request->get('laboratory_id')==0)
        {
            $cases = SuspectCase::whereBetween('sample_at', [$from, $to])->where(function ($q) {
            $q->whereIn('establishment_id', Auth::user()->establishments->pluck('id'))
                ->orWhere('user_id', Auth::user()->id);
        })->orderBy('suspect_cases.id', 'desc')->get();
        }
        else
        {
            $cases = SuspectCase::where('laboratory_id', $request->get('laboratory_id'))->whereBetween('sample_at', [$from, $to])->where(function ($q) {
                $q->whereIn('establishment_id', Auth::user()->establishments->pluck('id'))
                    ->orWhere('user_id', Auth::user()->id);
            })->orderBy('suspect_cases.id', 'desc')->get();
        }

        return view('lab.suspect_cases.reports.ownindexfilter', compact('request', 'cases', 'from', 'to','laboratories'));
    }




    public function tracing_minsal(Request $request)
    {

        if ($request->has('date_from') && $request->has('date_to')) {
            $date_from = $request->get('date_from');
            $date_to = $request->get('date_to');
        } else {
            $date_from = Carbon::now();
            $date_to = Carbon::now();
        }

        $patients = Patient::whereHas('tracing', function ($q) use ($date_to, $date_from) {
                $q->where('index', '1')
                    ->whereIn('establishment_id', auth()->user()->establishments->pluck('id'))
                    ->whereBetween('notification_at', [new Carbon($date_from), new Carbon($date_to)]);
            })
            ->with('contactPatient')
            ->with('tracing')
            ->with('suspectCases')
            ->get();

        //        dd($patients);

        return view('lab.suspect_cases.reports.tracing_minsal', compact('patients', 'request'));
    }

    public function tracing_minsal_by_patient(Request $request)
    {
        if ($request->has('run')) {
            $patients = Patient::whereHas('tracing', function ($q) {
                $q->where('index', '1')
                    ->whereIn('establishment_id', auth()->user()->establishments->pluck('id'));
            })
                ->where('run', $request->get('run'))
                ->orWhere('other_identification', $request->get('run'))
                ->with('contactPatient')
                ->with('tracing')
                ->with('suspectCases')
                ->get();
        } else {
            $patients = collect(new Patient);
        }

        return view('lab.suspect_cases.reports.tracing_minsal_by_patient', compact('patients', 'request'));
    }

    public function tracingByCommunes(Request $request)
    {


        if ($request->has('date')) {
            $date = $request->get('date');
        } else {
            $date = Carbon::now();
        }

        // ----------------------- crear arreglo ------------------------------
        $communes = Commune::where('region_id', env('REGION'))->orderBy('name')->get();
        foreach ($communes as $key => $commune) {
            $report[$commune->id]['Comuna'] = $commune->name;
            $report[$commune->id]['positives'] = 0;
            $report[$commune->id]['car'] = 0;
            $report[$commune->id]['curso'] = 0;
            $report[$commune->id]['terminado'] = 0;
        }

        $from = $request->get('date') . ' 00:00:00';
        $to = $request->get('date') . ' 23:59:59';

        $patients = Patient::whereHas('suspectCases', function ($q) use ($date) {
            $q->where('pcr_sars_cov_2', 'positive')
                ->whereDate('pcr_sars_cov_2_at', $date);
        })
            ->whereHas('demographic', function ($q) {
                $q->where('region_id', env('REGION'));
            })
            ->get();

        foreach ($patients as $patient) {

            $report[$patient->demographic->commune_id]['positives'] += 1;

            foreach ($patient->contactPatient as $contact) {
                if ($contact->patient_id == $patient->id) {
                    // dd($contact);
                    $report[$patient->demographic->commune_id]['car'] += 1;
                }
            }

            if ($patient->tracing) {
                if ($patient->tracing->status == 1) {
                    $report[$patient->demographic->commune_id]['curso'] += 1;
                }
                if ($patient->tracing->status == null or $patient->tracing->status == 0) {
                    $report[$patient->demographic->commune_id]['terminado'] += 1;
                }
            }
        }

        //dd($report);



        // if ($patients->count() == 0){
        //     session()->flash('info', 'No existen casos positivos o no hay casos con dirección.');
        //     //return redirect()->route('home');
        // }



        $communes_ids = array_map('trim', explode(",", env('COMUNAS')));
        $communes = Commune::whereIn('id', $communes_ids)->get();



        return view('lab.suspect_cases.reports.tracingbycommune', compact('request', 'report', 'communes', 'patients'));
    }

    public function case_tracing_export()
    {
        $env_communes = array_map('trim', explode(",", env('COMUNAS')));

        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereHas('demographic', function ($q) use ($env_communes) {
            $q->whereIn('commune_id', $env_communes);
        })
            ->with('inmunoTests')
            ->get();

        $max_cases = 0;
        $max_cases_inmuno = 0;
        foreach ($patients as $patient) {
            if ($max_cases < $patient->suspectCases->count()) {
                $max_cases = $patient->suspectCases->count();
            }
            if ($max_cases_inmuno < $patient->inmunoTests->count()) {
                $max_cases_inmuno = $patient->inmunoTests->count();
            }
        }

        /* CONTRUCCION DEL HEADER DEL ARCHIVO */
        $columnas_paciente = array(
            'Paciente',
            'Identificador',
            'Edad',
            'Sexo',
            'Comuna',
            'Nacionalidad',
            'Telefonos',
            'Estado',
        );

        $columnas_covid = array();
        for ($i = 1; $i <= $max_cases; $i++) {
            $columnas_covid[] = 'PCR ' . $i;
            $columnas_covid[] = 'Fecha Muestra ' . $i;
            $columnas_covid[] = 'Fecha Resultado ' . $i;
            $columnas_covid[] = 'Resultado ' . $i;
            $columnas_covid[] = 'S ' . $i;
        }

        $columnas_inmuno = array();
        for ($i = 1; $i <= $max_cases_inmuno; $i++) {
            $columnas_inmuno[] = 'IgG/IgM ' . $i;
            $columnas_inmuno[] = 'Fecha Test' . $i;
            $columnas_inmuno[] = 'IgG ' . $i;
            $columnas_inmuno[] = 'IgM ' . $i;
            $columnas_inmuno[] = 'Control ' . $i;
        }

        $columnas_cases = array(
            'Fecha IFD',
            'IFD',
            'Origen',
            'S.Epidemiológica',
            'Epivigila',
            'PAHO FLU',
            'Gestante',
            'Contacto directo',
            'Fecha envío',
            'Laboratorio',
            'Fecha Entrega Resultado',
            'Mecanismo',
            'Fecha Alta',
            'Observación',
        );

        $columnas = array_merge($columnas_paciente, $columnas_covid, $columnas_inmuno, $columnas_cases);

        foreach ($patients as $key => $patient) {
            $casos[$key][] = $patient->fullName;
            $casos[$key][] = $patient->identifier;
            $casos[$key][] = $patient->age;
            $casos[$key][] = $patient->genderEsp;
            $casos[$key][] = ($patient->demographic and $patient->demographic->commune) ? $patient->demographic->commune->name : '';
            $casos[$key][] = ($patient->demographic) ? $patient->demographic->nationality : '';
            $casos[$key][] = ($patient->demographic) ? $patient->demographic->fullTelephones : '';
            $casos[$key][] = $patient->status;
            foreach ($patient->suspectCases as $suspectCase) {
                $casos[$key][] = $suspectCase->id;
                $casos[$key][] = $suspectCase->sample_at->format('Y-m-d');
                $casos[$key][] = ($suspectCase->pcr_sars_cov_2_at) ? $suspectCase->pcr_sars_cov_2_at->format('Y-m-d') : '';
                $casos[$key][] = $suspectCase->covid19;
                $casos[$key][] = $suspectCase->symptoms;
            }
            for ($i = $patient->suspectCases->count(); $i < $max_cases; $i++) {
                $casos[$key][] = '';
                $casos[$key][] = '';
                $casos[$key][] = '';
                $casos[$key][] = '';
                $casos[$key][] = '';
            }
            foreach ($patient->inmunoTests as $inmunoTest) {
                $casos[$key][] = $inmunoTest->id;
                $casos[$key][] = ($inmunoTest->register_at) ? $inmunoTest->register_at->format('Y-m-d H:i:s') : '';
                $casos[$key][] = strtoupper(($inmunoTest->IgValue) ? $inmunoTest->IgValue : '');
                $casos[$key][] = strtoupper(($inmunoTest->ImValue) ? $inmunoTest->ImValue : '');
                $casos[$key][] = strtoupper($inmunoTest->ControlValue);
            }
            for ($i = $patient->inmunoTests->count(); $i < $max_cases_inmuno; $i++) {
                $casos[$key][] = '';
                $casos[$key][] = '';
                $casos[$key][] = '';
                $casos[$key][] = '';
                $casos[$key][] = '';
            }
            $casos[$key][] = ($patient->suspectCases->first()->result_ifd_at) ? $patient->suspectCases->first()->result_ifd_at->format('Y-m-d') : '';
            $casos[$key][] = $patient->suspectCases->first()->result_ifd;
            $casos[$key][] = ($patient->suspectCases->first()->establishment) ? $patient->suspectCases->first()->establishment->alias : '';
            $casos[$key][] = $patient->suspectCases->first()->epidemiological_week;
            $casos[$key][] = $patient->suspectCases->first()->epivigila;
            $casos[$key][] = $patient->suspectCases->first()->paho_flu;
            $casos[$key][] = ($patient->suspectCases->first()->gestation == 1) ? 'Sí' : '';
            $casos[$key][] = ($patient->suspectCases->first()->close_contact == 1) ? 'Sí' : '';
            $casos[$key][] = ($patient->suspectCases->first()->sent_external_lab_at) ? $patient->suspectCases->first()->sent_external_lab_at->format('Y-m-d') : '';
            $casos[$key][] = $patient->suspectCases->first()->procesingLab;
            $casos[$key][] = ($patient->suspectCases->first()->notification_at) ? $patient->suspectCases->first()->notification_at->format('Y-m-d') : '';
            $casos[$key][] = $patient->suspectCases->first()->notification_mechanism;
            $casos[$key][] = ($patient->suspectCases->first()->discharged_at) ? $patient->suspectCases->first()->discharged_at->format('Y-m-d') : '';
            $casos[$key][] = $patient->suspectCases->first()->observation;
        }

        $callback = function () use ($casos, $columnas) {

            $file = fopen('php://output', 'w');
            fputs($file, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

            fputcsv($file, $columnas, ';');

            foreach ($casos as $fila) {
                fputcsv($file, $fila, ';');
            }
            fclose($file);
        };

        $headers = array(
            "Content-type" => "text",
            "Content-Disposition" => "attachment; filename=seguimiento.csv",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        );

        return response()->stream($callback, 200, $headers);
    }



    /*****************************************************/
    /*                  REPORTE MINSAL                   */
    /*****************************************************/
    public function report_minsal(Request $request, Laboratory $laboratory)
    {

        if ($from = $request->has('from')) {
            $from = $request->get('from');
            $to = $request->get('to');
        } else {
            $from = date("Y-m-d 21:00", time() - 60 * 60 * 24);
            $to = date("Y-m-d 20:59");
        }

        $externos = SARSCoV2External::whereBetween('result_at', [$from, $to])->get();

        $cases = SuspectCase::where('laboratory_id', $laboratory->id)
            ->whereBetween('pcr_sars_cov_2_at', [$from, $to])
            ->whereNull('external_laboratory')
            ->get()
            ->sortByDesc('pcr_sars_cov_2_at');
        return view('lab.suspect_cases.reports.minsal', compact('cases', 'laboratory', 'externos', 'from', 'to', 'request'));
    }

    /*****************************************************/
    /*                  REPORTE RECEPCIONADOS            */
    /*****************************************************/
    public function reception_report(Request $request, Laboratory $laboratory)
    {

        if ($from = $request->has('from')) {
            $from = $request->get('from');
            $to = $request->get('to');
        } else {
            $from = date("Y-m-d 21:00", time() - 60 * 60 * 24);
            $to = date("Y-m-d 20:59");
        }

        $externos = SARSCoV2External::whereBetween('result_at', [$from, $to])->get();

        $cases = SuspectCase::where('laboratory_id', $laboratory->id)
            ->whereBetween('reception_at', [$from, $to])
            ->whereNull('external_laboratory')
            ->get()
            ->sortByDesc('reception_at');
        return view('lab.suspect_cases.reports.reception_report', compact('cases', 'laboratory', 'externos', 'from', 'to', 'request'));
    }


    /*****************************************************/
    /*                  REPORTE MINSAL WS                */
    /*****************************************************/
    public function report_minsal_ws(Request $request)
    {
        $from = '2020-09-01 00:00';//date("Y-m-d 21:00:00", time() - 60 * 60 * 24);
        $to = date("Y-m-d 20:59:59");

        $laboratory_id = 1;
        if ($request->all()) {
            $laboratory_id = $request->laboratory_id;
        } else {
            $request->laboratory_id = 1;
        }

        $cases = SuspectCase::where('laboratory_id',$laboratory_id)
                ->whereBetween('pcr_sars_cov_2_at', [$from, $to])
                ->whereNull('external_laboratory')
                ->whereNULL('minsal_ws_id')
                // ->where('id',20370)
                ->get()
                ->sortByDesc('pcr_sars_cov_2_at');
                 //dd($cases);

        $laboratories = Laboratory::where('minsal_ws',1)->get();
        //dd($laboratories);
        // $laboratories = Laboratory::where('id',1)->get();

        return view('lab.suspect_cases.reports.minsal_ws', compact('cases', 'request', 'laboratories')); //,'externos'));
    }


    /*****************************************************/
    /*                    WS - Minsal                    */
    /*****************************************************/
    public function ws_minsal(Request $request)
    {

         //dd($request);
        $from = '2020-09-01 00:00';//date("Y-m-d 21:00:00", time() - 60 * 60 * 24);
        $to = date("Y-m-d 20:59:59");

        $laboratory_id = 1;
        if ($request->all()) {
            $laboratory_id = $request->laboratory_id;
        } else {
            $request->laboratory_id = 1;
        }

        $laboratories = Laboratory::where('minsal_ws',1)->get();
       // dd($laboratories);
        // $laboratories = Laboratory::where('id',1)->get();

        $cases = SuspectCase::where('laboratory_id', $request->laboratory_id)
            ->whereBetween('pcr_sars_cov_2_at', [$from, $to])
            ->whereNull('external_laboratory')
            ->whereNULL('minsal_ws_id')
            // ->where('id',20370)
            ->get()
            ->sortByDesc('pcr_sars_cov_2_at');
        // dd($cases);

        foreach ($cases as $key => $case) {
            // if ($case->run_medic != 0) {
            if ($case->patient->demographic) {
                // dd("");
                $response = WSMinsal::crea_muestra($case);
                if ($response['status'] == 0) {
                    session()->flash('info', 'Error al subir muestra ' . $case->id . ' a MINSAL. ' . $response['msg']);
                    return redirect()->back();
                    // return view('lab.suspect_cases.reports.minsal_ws', compact('cases', 'request','laboratories'));
                } else {
                    $response = WSMinsal::recepciona_muestra($case);
                    if ($response['status'] == 0) {
                        session()->flash('info', 'Error al recepcionar muestra ' . $case->id . ' en MINSAL. ' . $response['msg']);
                        return redirect()->back();
                        // return view('lab.suspect_cases.reports.minsal_ws', compact('cases', 'request','laboratories'));
                    } else {
                        $response = WSMinsal::resultado_muestra($case);
                        if ($response['status'] == 0) {
                            session()->flash('info', 'Error al subir resultado de muestra ' . $case->id . ' en MINSAL. ' . $response['msg']);
                            return redirect()->back();
                            // return view('lab.suspect_cases.reports.minsal_ws', compact('cases', 'request','laboratories'));
                        }
                    }
                }
            } else {
                session()->flash('info', 'Error al subir la muestra ' . $case->id . ' en MINSAL. No existen datos demográficos asociados.');
                return redirect()->back();
            }
            // }else{
            //     session()->flash('info', 'No se detectó run de médico registrado en muestra:  ' . $case->id);
            //     return redirect()->back();
            // }
        }

        session()->flash('success', 'Se ha subido la información a sistema MINSAL.');
        // return redirect()->back();
        return view('lab.suspect_cases.reports.minsal_ws', compact('cases', 'request', 'laboratories'));
    }

    /*****************************************************/
    /*                  REPORTE SEREMI                   */
    /*****************************************************/
    public function report_seremi(Laboratory $laboratory)
    {

        $cases = SuspectCase::where('laboratory_id', $laboratory->id)->get()->sortDesc();
        return view('lab.suspect_cases.reports.seremi', compact('cases', 'laboratory'));
    }



    /*****************************************************/
    /*                REPORTE GESTANTES                  */
    /*****************************************************/
    public function gestants()
    {
        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('gestation', 1);
        })->with('suspectCases')->get();

        return view('lab.suspect_cases.reports.gestants', compact('patients'));
    }

    /*****************************************************/
    /*              CONTADOR DE POSITIVOS                */
    /*****************************************************/
    public function countPositives(Request $request)
    {
        $patients = Patient::positivesList();

        if ($request->input('residence')) {
            $bookings = Booking::where('status', 'Residencia Sanitaria')
                ->whereHas('patient', function ($q) {
                    $q->where('status', 'Residencia Sanitaria');
                })->get();
            $booking_ct = $bookings->where('room.residence_id', $request->input('residence'))->count();
            return $patients->count() . "\n" . $booking_ct;
        }

        return $patients->count();
    }

    /**
     * En desarrollo. Web service que obtiene data de archivos HL7 enviados por herramienta de integración
     * Mirth Connect.
     * @param Request $request
     */
    public function getHl7Files(Request $request)
    {
        $patientId = $request->input('patient_id');
        $patientNames = $request->input('patient_names');
        $patientFamilyFather = $request->input('patient_family_father');
        $patientFamilyMother = $request->input('patient_family_mother');
        $pcrSarsCov2At = Carbon::parse($request->input('observation_datetime'));
        $pcrSarsCov2 = $request->input('observation_value');
        //        $sampleAt = Carbon::parse($request->input('sample_observation_datetime'));
        $sampleAt = Carbon::parse($request->input('message_datetime'));
        error_log('---------------WEBSERVICE: HL7 FILES TEST----------------');
        error_log('patientId: ' . $patientId);
        error_log('patientNames: ' . $patientNames);
        error_log('patientFamilyFather: ' . $patientFamilyFather);
        error_log('patientFamilyMother: ' . $patientFamilyMother);
        error_log('pcrSarsCov2At: ' . $pcrSarsCov2At);
        error_log('pcrSarsCov2: ' . $pcrSarsCov2);
        error_log('sampleAt: ' . $sampleAt->toDateString());

        if (strtoupper($pcrSarsCov2) == "N") {
            $pcrSarsCov2 = "negative";
        }
        if (strtoupper($pcrSarsCov2) == "P") {
            $pcrSarsCov2 = "positive";
        }
        if (strtoupper($pcrSarsCov2) == "ENM") {
            $pcrSarsCov2 = "rejected";
        }
        if (strtoupper($pcrSarsCov2) == "INDET") {
            $pcrSarsCov2 = "undetermined";
        }
        //            if(strtoupper($pcrSarsCov2) == "PENDIENTE"){$pcrSarsCov2 = "pending";}

        $suspectCase = SuspectCase::whereHas('patient', function ($q) use ($patientFamilyFather, $patientFamilyMother, $patientNames) {
            $q->where('fathers_family', 'LIKE', '%' . $patientFamilyFather . '%')
                ->where('mothers_family', 'like', '%' . $patientFamilyMother . '%')
                ->where('name', 'like', '%' . $patientNames . '%');
        })->whereDate('sample_at', $sampleAt->toDateString())
            ->orderBy('updated_at', 'desc')->first();

        if ($suspectCase != null) {
            $suspectCase->pcr_sars_cov_2 = $pcrSarsCov2;
            $suspectCase->pcr_sars_cov_2_at = $pcrSarsCov2At;
            $suspectCase->save();
            error_log($suspectCase);
        }
    }

    public function case_chart(Request $request)
    {
        // $from = $request->has('from'). ' 00:00:00';
        // $to   = $request->has('to'). ' 23:59:59';
        if ($from = $request->has('from')) {
            $from = $request->get('from') . ' 00:00:00';
            $to = $request->get('to') . ' 23:59:59';
        } else {
            $from = Carbon::now()->firstOfMonth();
            $to = Carbon::now()->lastOfMonth();
        }

        $suspectCases = SuspectCase::whereBetween('sample_at', [$from, $to])->get();
        // ::latest('id')->get();
        $data = array();
        foreach ($suspectCases as $key => $suspectCase) {
            if ($suspectCase->pcr_sars_cov_2 == 'positive' || $suspectCase->pcr_sars_cov_2 == 'pending') {
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['day'] = date("d", strtotime($suspectCase->sample_at));
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['month'] = date("m", strtotime($suspectCase->sample_at)) - 1;
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['year'] = date("Y", strtotime($suspectCase->sample_at));
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['pendientes'] = 0;
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['positivos'] = 0;
            }
            // $suspectCase->day = date("d", strtotime($suspectCase->sample_at));
            // $suspectCase->month = date("m", strtotime($suspectCase->sample_at))-1;
            // $suspectCase->year = date("Y", strtotime($suspectCase->sample_at));


        }

        foreach ($suspectCases as $key => $suspectCase) {
            if ($suspectCase->pcr_sars_cov_2 == 'pending') {
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['pendientes'] += 1;
            }
            if ($suspectCase->pcr_sars_cov_2 == 'positive') {
                $data[date("d", strtotime($suspectCase->sample_at)) . "/" . date("m", strtotime($suspectCase->sample_at)) . "/" . date("Y", strtotime($suspectCase->sample_at))]['positivos'] += 1;
            }
        }

        return view('lab.suspect_cases.reports.case_chart', compact('suspectCases', 'data', 'from', 'to'));
    }



    public function exams_with_result(Request $request)
    {
        $from = Carbon::now()->subDays(2);
        $to = Carbon::now();
        //        $files = File::whereBetween('created_at', [$from, $to])
        //                   ->whereHas('suspectCase', function ($query) {
        //                        $query->where('pcr_sars_cov_2', 'like', 'positive');
        //                    })
        //                   ->orderBy('created_at','DESC')->get();

        $suspectCases = SuspectCase::whereBetween('pcr_sars_cov_2_at', [$from, $to])
            ->where('pcr_sars_cov_2', 'like', 'positive')
            ->where('file', true)
            ->orderBy('created_at', 'DESC')->get();


        $suspectCasesUnap = SuspectCase::whereBetween('created_at', [$from, $to])
            ->where('pcr_sars_cov_2', 'like', 'positive')
            ->where('laboratory_id', 2)
            ->get();

        return view('lab.suspect_cases.reports.exams_with_result', compact('suspectCases', 'suspectCasesUnap'));
    }

    /**
     * Obtiene suspectsCases positivos con datos de demographics por
     * rango de fecha
     * @param Request $request
     * @return Application|Factory|View
     */
    public function positivesByDateRange(Request $request)
    {

        if ($from = $request->has('from')) {
            $from = $request->get('from') . ' 00:00:00';
            $to = $request->get('to') . ' 23:59:59';
        } else {
            $from = Carbon::yesterday();
            $to = Carbon::now();
        }

        $communes_ids = array_map('trim', explode(",", env('COMUNAS')));
        $communes = Commune::whereIn('id', $communes_ids)->get();

        $selectedCommune = $request->get('commune');

        $suspectCases = SuspectCase::whereBetween('pcr_sars_cov_2_at', [$from, $to])
            ->whereIn('pcr_sars_cov_2', ['positive','negative','rejected','undetermined','pending'])
            ->when($selectedCommune, function ($q) use ($selectedCommune){
                return $q->whereHas('patient', function($q) use ($selectedCommune){
                    $q->whereHas('demographic', function ($q) use ($selectedCommune){
                        $q->where('commune_id', $selectedCommune);
                    });
                });
            })
            ->orderBy('pcr_sars_cov_2_at')
            ->get();

        return view('lab.suspect_cases.reports.positivesByDateRange', compact('suspectCases', 'from', 'to', 'communes', 'selectedCommune'));
    }

    /**
     * Obtiene casos pendientes  por
     * rango de fecha
     * @param Request $request
     * @return Application|Factory|View
     */
    public function pendientesByDateRange(Request $request){

        if($from = $request->has('from')){
            $from = $request->get('from'). ' 00:00:00';
            $to = $request->get('to'). ' 23:59:59';
        }else{
            $from = Carbon::yesterday();
            $to = Carbon::now();
        }

        $communes_ids = array_map('trim',explode(",",env('COMUNAS')));
        $communes = Commune::whereIn('id', $communes_ids)->get();

        $selectedCommune = $request->get('commune');

        $suspectCases = SuspectCase::whereBetween('sample_at', [$from, $to])
            ->whereIn('pcr_sars_cov_2', ['pending'])
            ->when($selectedCommune, function ($q) use ($selectedCommune){
                return $q->whereHas('patient', function($q) use ($selectedCommune){
                    $q->whereHas('demographic', function ($q) use ($selectedCommune){
                        $q->where('commune_id', $selectedCommune);
                    });
                });
            })
            ->orderBy('pcr_sars_cov_2_at')
            ->get();

        return view('lab.suspect_cases.reports.pendientesByDateRange', compact('suspectCases', 'from', 'to', 'communes', 'selectedCommune'));
    }

    /*****************************************************/
    /*            REPORTE HOSPITALIZADOS                 */
    /*****************************************************/
    public function hospitalized()
    {
        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereIn('status', [
            'Hospitalizado Básico',
            'Hospitalizado Medio',
            'Hospitalizado UTI',
            'Hospitalizado UCI',
            'Hospitalizado UCI (Ventilador)'
        ])
            ->orderBy('status')
            ->get();

        return view('lab.suspect_cases.reports.hospitalized', compact('patients'));
    }

    /*****************************************************/
    /*     REPORTE HOSPITALIZADOS POR COMUNAS USUARIO    */
    /*****************************************************/
    public function hospitalizedByUserCommunes()
    {

        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->whereIn('status', [
            'Hospitalizado Básico',
            'Hospitalizado Medio',
            'Hospitalizado UTI',
            'Hospitalizado UCI',
            'Hospitalizado UCI (Ventilador)'
        ])->whereHas('demographic', function ($q) {
            $q->whereIn('commune_id', auth()->user()->communes());
        })
            ->orderBy('status')
            ->get();

        $byUserCommune = true;

        return view('lab.suspect_cases.reports.hospitalized', compact('patients', 'byUserCommune'));
    }

    /*****************************************************/
    /*            REPORTE FALLECIDOS                     */
    /*****************************************************/
    public function deceased()
    {
        $patients = Patient::whereHas('suspectCases', function ($q) {
            $q->where('pcr_sars_cov_2', 'positive');
        })->where('status', 'Fallecido')->with('suspectCases')->with('demographic')->orderBy('deceased_at')->get();

        return view('lab.suspect_cases.reports.deceased', compact('patients'));
    }


    /*****************************************************/
    /*            REPORTE SISTEMAS EXPERTOS              */
    /*****************************************************/
    public function reporteExpertos()
    {
        $from = Carbon::yesterday();
        $to = Carbon::now();

        $patients = Patient::whereHas('suspectCases', function ($q) use ($from, $to) {
            $q->whereBetween('pcr_sars_cov_2_at', [$from, $to]);
        })->with('suspectCases')->get();

        //dd($patients);
        return response()->json($patients);
    }

    /*****************************************************/
    /*            REPORTE LICENCIA MEDICA                */
    /*****************************************************/
    public function requires_licence()
    {
        $patients = Patient::whereHas('tracing', function ($q) {
                $q->where('status', '>', '0')
                    ->where('requires_licence', 1)
                    ->whereIn('establishment_id', auth()->user()->establishments->pluck('id'));
            })
            ->get();

        return view('lab.suspect_cases.reports.requires_licence', compact('patients'));
    }

    /*****************************************************/
    /*            REPORTE USUARIOS RENDIMIENTO                */
    /*****************************************************/
    public function user_performance(Request $request)
    {
        /* USUARIOS DE MIS ESTABLECIMIENTOS */
        $users = User::whereHas('establishments', function ($q) {
            $q->whereIn('establishment_id', auth()->user()->establishments->pluck('id'));
        })
            ->has('events')
            ->orderBy('name', 'ASC')
            ->get();

        $events = Event::whereDate('event_at', $request->date)
            ->where('user_id', $request->user)
            ->get();

        /* ------------------- CREAR ARRAY DE RESUMEN -----------------------*/
        $events_type = EventType::all();
        foreach ($events_type as $key => $type) {
            $events_resume[$type->name] = 0;
        }
        $events_resume['total'] = 0;

        foreach ($events as $key => $event) {
            $events_resume[$event->type->name] += 1;
            $events_resume['total'] += 1;
        }
        /* ----------------------------------------------------------------- */

        return view('lab.suspect_cases.reports.user_performance', compact('users', 'request', 'events', 'events_resume'));
    }

    public function pendingMoreThanTwoDays()
    {
        $suspectCases = SuspectCase::where('pcr_sars_cov_2', 'pending')
            ->where('reception_at', '<=', Carbon::now()->subDays(2))
            ->get();

        return view('lab.suspect_cases.reports.pending_more_than_two_days', compact('suspectCases'));
    }

    public function suspectCaseByCommune(Request $request)
    {
        if ($from = $request->has('from')) {
            $from = $request->get('from') . ' 00:00:00';
            $to = $request->get('to') . ' 23:59:59';
        } else {
            $from = Carbon::yesterday();
            $to = Carbon::now();
        }

        $communes_ids = Auth()->user()->communes();
        $communes = Commune::whereIn('id', $communes_ids)->get();
        $selectedCommune = $request->get('commune');

        $suspectCases = SuspectCase::whereBetween('pcr_sars_cov_2_at', [$from, $to])
            ->when($selectedCommune, function ($q) use ($selectedCommune) {
                return $q->whereHas('patient', function ($q) use ($selectedCommune) {
                    $q->whereHas('demographic', function ($q) use ($selectedCommune) {
                        $q->where('commune_id', $selectedCommune);
                    });
                });
            })
            ->orderBy('pcr_sars_cov_2_at')
            ->get();

        return view('lab.suspect_cases.reports.suspect_cases_by_commune', compact('suspectCases', 'from', 'to', 'communes', 'selectedCommune'));
    }


    /**
     * Listado de Casos Sospechosos que no han sido
     * recepcionados
     * @return Application|Factory|View
     */
    public function withoutReception()
    {
        $cases = SuspectCase::whereNull('receptor_id')->get();
        return view('lab.suspect_cases.reports.without_reception', compact('cases'));
    }
}
