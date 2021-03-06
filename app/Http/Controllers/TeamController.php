<?php

namespace App\Http\Controllers;

use App\Team;
use App\Round;
use App\EnemyBoss;
use App\SecretWeapon;
use App\Material;
use App\Events\SendGift;
use Carbon\Carbon;
use Illuminate\Http\Request;
use DB;
use Auth;

class TeamController extends Controller
{
    // [RICKY] Reload halaman dashboard peserta
    public function dashboard() {
        $this->authorize('team');

        $id_team = Auth::user()->team;
        
        $team_info = Team::find($id_team);
        $enemy_info = EnemyBoss::find(1);
        $round_info = Round::find(1);
        $secret_weapon = SecretWeapon::find(1);
        $histories_team = DB::table('histories')->where('teams_id', $id_team)->get();
        $difference = strtotime($round_info->time_end) - strtotime(date("Y-m-d H:i:s"));
        $equipment_list = DB::select(DB::raw("SELECT e.id AS id_equipment, e.name AS nama_equipment, coalesce(et.amount, '0') AS jumlah_equipment, e.equipment_types_id AS tipe_equipment, e.description as deskripsi_equipment FROM equipments AS e LEFT JOIN (SELECT * FROM equipment_team WHERE teams_id = $id_team) AS et ON e.id = et.equipments_id WHERE e.id NOT IN (1,2,3)ORDER BY id_equipment"));
        $material_list = DB::select(DB::raw("SELECT m.id AS materials_id, m.name AS nama_material, coalesce(mt.amount, '0') AS jumlah FROM materials AS m LEFT JOIN (SELECT * FROM material_team WHERE teams_id = $id_team) AS mt ON m.id = mt.materials_id ORDER BY materials_id"));
        $friend_list = DB::table('teams')->select('id','name')->whereNotIn('id',[$id_team])->get();

        $equipment_requirement_list = array();
        for ($i = 4; $i <= 13; $i++) {
            $equipment_requirement = DB::table('equipment_requirement')->join('materials', 'equipment_requirement.materials_id', '=', 'materials.id')->where('equipment_requirement.equipments_id', $i)->select('materials.name AS nama_material', 'equipment_requirement.amount_need AS jumlah_material')->get();

            $equipment_requirement_list[] = $equipment_requirement;
        }

        foreach ($equipment_list as $key => $value) {
            $equipment_list[$key]->requirement = $equipment_requirement_list[$key];
        }

        return view('peserta.welcome', [
            'team' => $team_info,
            'boss' => $enemy_info,
            'round' => $round_info,
            'weapon' => $secret_weapon,
            'times' => $difference,
            'equipments' => $equipment_list,
            'material' =>$material_list,
            'friend' =>$friend_list,
            'histories' => $histories_team,
            'materials' => Material::all()
        ]);
    }

    // [RICKY] Mendapatkan list material yang dibutuhkan saat click crafting
    public function getEquipmentRequirement(Request $request) {
        $this->authorize('team');
        $equipment_requirement = DB::table('equipment_requirement')->join('materials', 'equipment_requirement.materials_id', '=', 'materials.id')->where('equipment_requirement.equipments_id', $request->get('id_equipment'))->select('materials.name AS nama_material', 'equipment_requirement.amount_need AS jumlah_material')->get();
        
        return response()->json(array(
            'equipment_requirement' => $equipment_requirement
        ), 200);
    }

    // [RICKY] Crafting equipment berdasarkan material yang dimiliki
    public function craftingEquipment(Request $request) {
        $this->authorize('team');

        $id_team = Auth::user()->team;
        $team_detail = Team::find($id_team);
        $round_detail = Round::find(1);
        $id_equipment = $request->get('id_equipment');
        $amount = $request->get('amount');
        $message = "Material tidak mencukupi";
        $material_update = null;

        if ($team_detail->hp_amount > 0) {
            if (!$round_detail->action) {
                $equipment_requirement = DB::table('equipment_requirement')->where('equipments_id', $id_equipment)->get();

                // [RICKY] Lakukan pengecekan apakah material yang dimiliki mencukupi
                foreach ($equipment_requirement as $er) {
                    $material_team = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $er->materials_id)->get();
                    if (count($material_team) > 0) {
                        if ($material_team[0]->amount >= ($er->amount_need * $amount)) {
                            $crafting_result = true; 
                        } else {
                            $crafting_result = false;
                            break;
                        }
                    } else {
                        $crafting_result = false;
                        break;
                    }
                }
                
                if ($crafting_result) {
                    // [RICKY] Lakukan pengurangan material yang dimiliki apabila persyaratan tercukupi
                    foreach ($equipment_requirement as $er) {
                        $kurangi_material_team = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $er->materials_id)->decrement('amount', $er->amount_need * $amount);
                        $get_new_material = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $er->materials_id)->select('materials_id', 'amount')->get();
                        $material_update[] = $get_new_material[0];
                    }

                    // [RICKY] Menambah equipment yang di crafting
                    $check_equipment = DB::table('equipment_team')->where('teams_id', $id_team)->where('equipments_id', $id_equipment)->get();
                    if (count($check_equipment) > 0) {
                        $update_equipment = DB::table('equipment_team')->where('teams_id', $id_team)->where('equipments_id', $id_equipment)->increment('amount', $amount);
                    } else {
                        $insert_equipment = DB::table('equipment_team')->where('teams_id', $id_team)->where('equipments_id', $id_equipment)->insert([
                            'equipments_id'=> $id_equipment,
                            'teams_id'=> $id_team,
                            'amount'=> $amount
                        ]);
                    }

                    $equipment = DB::table('equipments')->where('id', $id_equipment)->get();
                    $message = "Berhasil crafting ".$amount." equipment ".$equipment[0]->name;

                    $insert_history = DB::table('histories')->insert([
                        'teams_id' => $id_team,
                        'name' => $message,
                        'type' => 'CRAFT',
                        'time' =>  Carbon::now(),
                        'round' => $round_detail->round
                    ]);
                    
                    $message = "<tr><td><p><b>[CRAFT]</b><small> ".date('H:i:s')."</small><br><span>".$message."</span></p></td></tr>";
                }
            } else {
                $crafting_result = false;
                $message = "Tidak dapat crafting equipment di sesi action";
            }
        } else {
            $crafting_result = false;
            $message = "Timmu tidak dapat bermain lagi!";
        }

        return response()->json(array(
            'crafting_result' => $crafting_result,
            'message' => $message,
            'material_update' => $material_update
        ), 200);
    }

    // [RICKY] Use equipment yang dimiliki
    public function useEquipment(Request $request) {
        $this->authorize('team');
        
        $id_team = Auth::user()->team;
        $id_equipment = $request->get('id_equipment');
        $team_detail = Team::find($id_team);
        $round_detail = Round::find(1);
        $use_access = false;
        $equipment_amount = 0;
        $new_hp = 0;

        if ($team_detail->hp_amount > 0) {
            if ($round_detail->action) {
                // [RICKY] Cek apakah memiliki equipment terkait
                $equipment_check = DB::table('equipment_team')->join('equipments', 'equipment_team.equipments_id', '=', 'equipments.id')->where('equipments_id', $id_equipment)->where('teams_id', $id_team)->get();
                
                if (count($equipment_check) == 0 || $equipment_check[0]->amount == 0) {
                    $message = "Tidak memiliki equipment untuk digunakan";
                } else {
                    // [RICKY] Cek equipment aktif yang sedang digunakan dan update status penggunaan equipment
                    $use_access = true;
                    if ($equipment_check[0]->equipment_types_id == 2 || $equipment_check[0]->equipment_types_id == 3) {
                        // [RICKY] Use buff/debuff
                        if ($team_detail->heal_status || $team_detail->buff_debuff_status) {
                            $message = "Tidak dapat menggunakan equipment buff/debuff karena sudah melakukan heal/buff/debuff";
                            $use_access = false;
                        } else {
                            if ($id_equipment == 4 && !$team_detail->debuff_disable) {
                                $update_status = DB::table('teams')->where('id', $id_team)->update(['debuff_disable' => true]);
                            } else if ($id_equipment == 5 && $team_detail->debuff_decreased == 0) {
                                $update_status = DB::table('teams')->where('id', $id_team)->update(['debuff_decreased' => 2]);
                            } else if ($id_equipment == 6 && !$team_detail->debuff_overtime) {
                                $update_status = DB::table('teams')->where('id', $id_team)->update(['debuff_overtime' => true]);
                            } else if ($id_equipment == 7 && $team_detail->buff_increased == 0) {
                                $update_status = DB::table('teams')->where('id', $id_team)->update(['buff_increased' => 2]);
                            } else if ($id_equipment == 8 && !$team_detail->buff_immortal) {
                                $round = Round::find(1);
                                if ($round->round % 4 == 0) {
                                    $update_status = DB::table('teams')->where('id', $id_team)->update(['buff_immortal' => true]);
                                } else {
                                    $use_access = false;
                                    $message = "Immortal Armor hanya dapat digunakan saat special turn";
                                }
                                
                            } else if ($id_equipment == 9 && $team_detail->buff_regeneration == 0) {
                                $team_hp = Team::find($id_team);
                                $update_status = DB::table('teams')->where('id', $id_team)->update(['buff_regeneration' => 1, 'hp_amount' => ($team_hp->hp_amount + 30)]);
                                $new_hp = $team_hp->hp_amount + 30;
                            } else {
                                $message = "Tidak dapat digunakan karena equipment serupa masih aktif";
                                $use_access = false;
                            }

                            if ($use_access) {
                                $update_status = DB::table('teams')->where('id', $id_team)->update(['buff_debuff_status' => true]);
                            }
                        }
                    } else if ($equipment_check[0]->equipment_types_id == 4) {
                        // [RICKY] Use shield
                        if ($team_detail->attack_status || $team_detail->heal_status || $team_detail->shield) {
                            $message = "Tidak dapat menggunakan equipment shield karena sudah melakukan attack/defense/heal";
                            $use_access = false;
                        } else {
                            $update_status = DB::table('teams')->where('id', $id_team)->update(['shield' => true]);
                        }
                    } else if ($equipment_check[0]->equipment_types_id == 5) {
                        // [RICKY] Use equipment healing
                        if ($team_detail->attack_status || $team_detail->heal_status || $team_detail->buff_debuff_status || $team_detail->shield) {
                            $message = "Tidak dapat menggunakan equipment heal karena sudah melakukan attack/defense/buff/debuff/heal";
                            $use_access = false;
                        } else {
                            $update_status = DB::table('teams')->where('id', $id_team)->update(['heal_status' => true]);
                            if ($id_equipment == 11) {
                                $hp_regen = 25;
                            } else if ($id_equipment == 12) {
                                $hp_regen = 50;
                            } else if ($id_equipment == 13) {
                                $hp_regen = 75;
                            }

                            if (($team_detail->hp_amount + $hp_regen) > 1000) {
                                $update_hp = DB::table('teams')->where('id', $id_team)->update(['hp_amount'=> 1000]);
                            } else {
                                $update_hp = DB::table('teams')->where('id', $id_team)->increment('hp_amount', $hp_regen);
                            }

                            $team = Team::find($id_team);
                            $new_hp = $team->hp_amount;
                        }
                    }
                }

                // [RICKY] Kurangi jumlah equipment dan dapatkan jumlah terbaru
                if ($use_access) {
                    $equipment = DB::table('equipments')->where('id', $id_equipment)->get();
                    $message = "Berhasil menggunakan equipment ".$equipment[0]->name;
                    $update_equipment = DB::table('equipment_team')->where('equipments_id', $id_equipment)->where('teams_id', $id_team)->decrement('amount', 1);
                    $get_equipment = DB::table('equipment_team')->where('equipments_id', $id_equipment)->where('teams_id', $id_team)->get();
                    $equipment_amount = $get_equipment[0]->amount;

                    $insert_history = DB::table('histories')->insert([
                        'teams_id' => $id_team,
                        'name' => $message,
                        'type' => 'USE',
                        'time' =>  Carbon::now(),
                        'round' => $round_detail->round
                    ]);

                    $message = "<tr><td><p><b>[USE]</b><small> ".date('H:i:s')."</small><br><span>".$message."</span></p></td></tr>";
                }
            } else {
                $use_access = false;
                $message = "Tidak dapat use equipment di sesi preparation";
            }
        } else {
            $use_access = false;
            $message = "Timmu tidak dapat bermain lagi!";
        }
        
        return response()->json(array(
            'use_result' => $use_access,
            'message' => $message,
            'update_hp' => $new_hp,
            'amount_now' => $equipment_amount
        ), 200);
    }

    // [RICKY] Mengupgrade weapon
    public function upgradeWeapon(Request $request) {
        $this->authorize('team');
        
        $id_team = Auth::user()->team;
        $team_detail = Team::find($id_team);
        $round_detail = Round::find(1);
        $level_weapon = $team_detail->weapon_level;
        $material_update = null;

        if ($team_detail->hp_amount > 0) {
            if (!$round_detail->action) {
                // [RICKY] Pastikan weapon dibawah level 3, karena level 3 adalah max
                if ($team_detail->weapon_level < 3) {
                    $weapon_requirement = DB::table('equipment_requirement')->where('equipments_id', $team_detail->weapon_level + 1)->get();

                    // [RICKY] Lakukan pengecekan apakah material untuk upgrade weapon mencukupi
                    foreach ($weapon_requirement as $wr) {
                        $material_team = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $wr->materials_id)->get();
                        
                        if (count($material_team) > 0) {
                            if ($material_team[0]->amount >= $wr->amount_need) {
                                $upgrade_weapon = true;
                            } else {
                                $upgrade_weapon = false;
                                break;
                            }
                        } else {
                            $upgrade_weapon = false;
                            break;
                        }
                    }
            
                    $message = "Material tidak mencukupi untuk upgrade weapon";
                    if ($upgrade_weapon) {
                        // [RICKY] Upgrade level weapon
                        $update_weapon = DB::table('teams')->where('id', $id_team)->increment('weapon_level', 1);
            
                        // [RICKY] Kurangi material untuk upgrade weapon
                        foreach ($weapon_requirement as $wr) {
                            $kurangi_material_weapon = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $wr->materials_id)->decrement('amount', $wr->amount_need);
                            $get_new_material = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $wr->materials_id)->select('materials_id', 'amount')->get();
                            $material_update[] = $get_new_material[0];
                        }

                        $level_weapon++;
                        $message = "Berhasil meningkatkan senjata";

                        $insert_history = DB::table('histories')->insert([
                            'teams_id' => $id_team,
                            'name' => $message,
                            'type' => 'UPGRADE',
                            'time' =>  Carbon::now(),
                            'round' => $round_detail->round
                        ]);

                        $message = "<tr><td><p><b>[UPGRADE]</b><small> ".date('H:i:s')."</small><br><span>".$message."</span></p></td></tr>";
                    }
                } else {
                    $upgrade_weapon = false;
                    $message = "Level weapon sudah mencapai maximal";
                }
            } else {
                $upgrade_weapon = false;
                $message = "Tidak dapat upgrade weapon di sesi action";
            }
        } else {
            $upgrade_weapon = false;
            $message = "Timmu tidak dapat bermain lagi!";
        }
        
        return response()->json(array(
            'status' => $upgrade_weapon,
            'message' => $message,
            'level_weapon' => $level_weapon,
            'material_update' => $material_update
        ), 200);
    }

    // [RICKY] Menyerang boss dengan menggunakan weapon
    public function attackWeapon() {
        $this->authorize('team');
        
        $id_team = Auth::user()->team;
        $team_detail = Team::find($id_team);
        $round_detail = Round::find(1);

        if ($team_detail->hp_amount > 0) {
            if ($round_detail->action) {
                if ($team_detail->weapon_level >= 1) {
                    if ($team_detail->attack_status || $team_detail->heal_status || $team_detail->shield) {
                        $attack_status = false;
                        $message = "Tidak dapat attack karena sudah melakukan attack/defense/heal";
                    } else {
                        $update_status = DB::table('teams')->where('id', $id_team)->update(['attack_status' => true]);
                        $attack_status = true;
                        $message = "Tim menyerang bos. Serangan akan dilancarkan pada akhir sesi";

                        $insert_history = DB::table('histories')->insert([
                            'teams_id' => $id_team,
                            'name' => $message,
                            'type' => 'ATTACK',
                            'time' =>  Carbon::now(),
                            'round' => $round_detail->round
                        ]);

                        $message = "<tr><td><p><b>[ATTACK]</b><small> ".date('H:i:s')."</small><br><span>".$message."</span></p></td></tr>";
                    }
                } else {
                    $attack_status = false;
                    $message = "Silahkan crafting senjata terlebih dahulu";
                }
            } else {
                $attack_status = false;
                $message = "Tidak dapat attack di sesi preparation";
            }
        } else {
            $attack_status = false;
            $message = "Timmu tidak dapat bermain lagi!";
        }

        return response()->json(array(
            'status' => $attack_status,
            'message' => $message
        ), 200);
    }

    //[Yobong] funtion untuk gift
    public function gift(Request $request){
        $this->authorize('team');
        
        $tujuan = $request->get('tujuan');
        $material = $request->get('material');
        $jumlah = $request->get('jumlah');
        $id_team = Auth::user()->team;
        $team_detail = Team::find($id_team);
        $material_detail = Material::find($material);
        $round_detail = Round::find(1);
        $msg = "Gagal mengirim material, Jumlah yang dikirim melebihi Inventory";
        $jumlah_sekarang = 0;
        $status = false;

        //cek jumlah kepunyaan
        $cek_jumlah = DB::table('material_team')->select('amount')->where('materials_id',$material)->where('teams_id',$id_team)->get();
        
        if ($team_detail->hp_amount > 0) {
            if (!$round_detail->action) {
                if (count($cek_jumlah) > 0) {
                    if ($jumlah > 0) {
                        $jumlah_sekarang = $cek_jumlah[0]->amount;

                        if ($jumlah <= $cek_jumlah[0]->amount) {
                            $cek = DB::table('material_team')
                                ->where('materials_id',$material)
                                ->where('teams_id',$tujuan)
                                ->get();
                            if (count($cek)>0) {
                                $update_material = DB::table('material_team')->where('teams_id', $tujuan)->where('materials_id', $material)->increment('amount', $jumlah);
                            } else {
                                $insert_material = DB::table('material_team')->where('teams_id', $tujuan)->where('materials_id', $material)->insert([
                                    'materials_id'=> $material,
                                    'teams_id'=> $tujuan,
                                    'amount'=> $jumlah
                                ]);
                            }
                            
                            $update_material_pemilik = DB::table('material_team')->where('teams_id', $id_team)->where('materials_id', $material)->decrement('amount', $jumlah);
                
                            $status = true;
                            $jumlah_sekarang = $cek_jumlah[0]->amount - $jumlah;
                            
                            $msg = "Memberikan ".$jumlah." ".$material_detail->name." ke Tim ".$tujuan;
                            $receiver_history = "Mendapatkan ".$jumlah." ".$material_detail->name." dari Tim Beta ".$id_team;

                            $insert_history = DB::table('histories')->insert([
                                [
                                    'teams_id' => $id_team,
                                    'name' => $msg,
                                    'type' => 'GIFT',
                                    'time' =>  Carbon::now(),
                                    'round' => $round_detail->round
                                ], 
                                [
                                    'teams_id' => $tujuan,
                                    'name' => $receiver_history,
                                    'type' => 'GIFT',
                                    'time' =>  Carbon::now(),
                                    'round' => $round_detail->round
                                ]
                            ]);

                            $msg = "<tr><td><p><b>[GIFT]</b><small> ".date('H:i:s')."</small><br><span>".$msg."</span></p></td></tr>";
                            $receiver_history = "<tr><td><p><b>[GIFT]</b><small> ".date('H:i:s')."</small><br><span>".$receiver_history."</span></p></td></tr>";
                            broadcast(new SendGift($tujuan, $receiver_history, $material, $jumlah))->toOthers();
                        }
                    } else {
                        $msg = "Masukkan jumlah material yang ingin dikirim";
                    }
                }
                else{
                    $msg = "Gagal mengirim material, tidak ada material tersebut di inventory";
                }
            } else {
                $msg = "Tidak dapat gift material di sesi action";
            }
        } else {
            $msg = "Timmu tidak dapat bermain lagi!";
        }
        
        return response()->json(array(
            'status' => $status,
            'msg' => $msg,
            'jumlah_sekarang'=> $jumlah_sekarang
        ), 200);
    }
}