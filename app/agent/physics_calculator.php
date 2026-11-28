<?php
/** Physics Calculator module. Explicit formulas only; no user expression evaluation. */
function lac_physics_formula_registry(): array {
 $f=[];
 $a=function($id,$domain,$name,$target,$inputs,$equation,$fn,$assumptions=[])use(&$f){$f[$id]=compact('id','domain','name','target','inputs','equation','assumptions')+['compute'=>$fn];};
 // Mechanics / kinematics
 $a('velocity','kinematics','Average velocity','velocity',['distance','time'],'v=s/t',fn($x)=>$x['distance']/$x['time']);
 $a('distance_uniform','kinematics','Uniform-motion distance','distance',['velocity','time'],'s=vt',fn($x)=>$x['velocity']*$x['time']);
 $a('acceleration','kinematics','Acceleration','acceleration',['final_velocity','initial_velocity','time'],'a=(v-v0)/t',fn($x)=>($x['final_velocity']-$x['initial_velocity'])/$x['time']);
 $a('final_velocity','kinematics','Final velocity','velocity',['initial_velocity','acceleration','time'],'v=v0+at',fn($x)=>$x['initial_velocity']+$x['acceleration']*$x['time']);
 $a('distance_accelerated','kinematics','Distance with constant acceleration','distance',['initial_velocity','time','acceleration'],'s=v0t+at²/2',fn($x)=>$x['initial_velocity']*$x['time']+.5*$x['acceleration']*$x['time']**2);
 $a('velocity_distance','kinematics','Velocity from displacement','velocity',['initial_velocity','acceleration','distance'],'v=sqrt(v0²+2as)',fn($x)=>sqrt(max(0,$x['initial_velocity']**2+2*$x['acceleration']*$x['distance'])));
 $a('freefall_distance','kinematics','Free-fall distance','distance',['gravity','time'],'h=gt²/2',fn($x)=>.5*$x['gravity']*$x['time']**2);
 $a('freefall_velocity','kinematics','Free-fall speed','velocity',['gravity','time'],'v=gt',fn($x)=>$x['gravity']*$x['time']);
 $a('freefall_time','kinematics','Free-fall time','time',['height','gravity'],'t=sqrt(2h/g)',fn($x)=>sqrt(2*$x['height']/$x['gravity']));
 $a('projectile_range','kinematics','Projectile range (same elevation)','range',['initial_speed','angle','gravity'],'R=v0²sin(2θ)/g',fn($x)=>$x['initial_speed']**2*sin(2*$x['angle']*M_PI/180)/$x['gravity'],['Same launch/landing elevation; angle in degrees']);
 $a('projectile_height','kinematics','Projectile maximum height','height',['initial_speed','angle','gravity'],'H=v0²sin²θ/(2g)',fn($x)=>$x['initial_speed']**2*sin($x['angle']*M_PI/180)**2/(2*$x['gravity']),['Angle in degrees']);
 // Forces, energy
 $a('force','mechanics','Newton second law','force',['mass','acceleration'],'F=ma',fn($x)=>$x['mass']*$x['acceleration']);
 $a('weight','mechanics','Weight','force',['mass','gravity'],'W=mg',fn($x)=>$x['mass']*$x['gravity']);
 $a('friction','mechanics','Friction force','force',['coefficient','normal_force'],'F=μN',fn($x)=>$x['coefficient']*$x['normal_force']);
 $a('spring_force','mechanics','Spring force magnitude','force',['spring_constant','displacement'],'F=kx',fn($x)=>$x['spring_constant']*abs($x['displacement']));
 $a('centripetal_force','mechanics','Centripetal force','force',['mass','velocity','radius'],'Fc=mv²/r',fn($x)=>$x['mass']*$x['velocity']**2/$x['radius']);
 $a('momentum','mechanics','Momentum','momentum',['mass','velocity'],'p=mv',fn($x)=>$x['mass']*$x['velocity']);
 $a('impulse','mechanics','Impulse','impulse',['force','time'],'J=FΔt',fn($x)=>$x['force']*$x['time']);
 $a('force_momentum','mechanics','Average force from momentum change','force',['delta_momentum','time'],'F=Δp/Δt',fn($x)=>$x['delta_momentum']/$x['time']);
 $a('gravity_force','mechanics','Universal gravitation','force',['mass1','mass2','distance','gravitational_constant'],'F=Gm1m2/r²',fn($x)=>$x['gravitational_constant']*$x['mass1']*$x['mass2']/$x['distance']**2);
 $a('gravity_field','mechanics','Gravity field strength','gravity',['central_mass','radius','gravitational_constant'],'g=GM/r²',fn($x)=>$x['gravitational_constant']*$x['central_mass']/$x['radius']**2);
 $a('work','energy','Work','work',['force','distance','angle'],'W=Fs cosθ',fn($x)=>$x['force']*$x['distance']*cos($x['angle']*M_PI/180),['Angle in degrees']);
 $a('kinetic_energy','energy','Kinetic energy','energy',['mass','velocity'],'E=mv²/2',fn($x)=>.5*$x['mass']*$x['velocity']**2);
 $a('potential_energy','energy','Gravitational potential energy','energy',['mass','gravity','height'],'E=mgh',fn($x)=>$x['mass']*$x['gravity']*$x['height']);
 $a('spring_energy','energy','Elastic potential energy','energy',['spring_constant','displacement'],'E=kx²/2',fn($x)=>.5*$x['spring_constant']*$x['displacement']**2);
 $a('power','energy','Power','power',['work','time'],'P=W/t',fn($x)=>$x['work']/$x['time']);
 $a('power_fv','energy','Power from force and speed','power',['force','velocity'],'P=Fv',fn($x)=>$x['force']*$x['velocity']);
 $a('efficiency','energy','Efficiency percent','efficiency',['useful_output','input'],'η=100 useful/input',fn($x)=>100*$x['useful_output']/$x['input']);
 // rotation and fluids
 $a('angular_velocity','rotation','Angular velocity','angular_velocity',['angle','time'],'ω=θ/t',fn($x)=>$x['angle']/$x['time'],['Angle in radians']);
 $a('angular_acceleration','rotation','Angular acceleration','angular_acceleration',['final_angular_velocity','initial_angular_velocity','time'],'α=Δω/t',fn($x)=>($x['final_angular_velocity']-$x['initial_angular_velocity'])/$x['time']);
 $a('centripetal_acceleration','rotation','Centripetal acceleration','acceleration',['velocity','radius'],'a=v²/r',fn($x)=>$x['velocity']**2/$x['radius']);
 $a('linear_speed','rotation','Linear speed from angular speed','velocity',['angular_velocity','radius'],'v=ωr',fn($x)=>$x['angular_velocity']*$x['radius']);
 $a('density','fluids','Density','density',['mass','volume'],'ρ=m/V',fn($x)=>$x['mass']/$x['volume']);
 $a('pressure','fluids','Pressure','pressure',['force','area'],'p=F/A',fn($x)=>$x['force']/$x['area']);
 $a('hydrostatic','fluids','Hydrostatic pressure','pressure',['density','gravity','depth'],'p=ρgh',fn($x)=>$x['density']*$x['gravity']*$x['depth']);
 $a('buoyancy','fluids','Archimedes force','force',['fluid_density','gravity','displaced_volume'],'FA=ρgV',fn($x)=>$x['fluid_density']*$x['gravity']*$x['displaced_volume']);
 $a('flow_rate','fluids','Volumetric flow rate','flow_rate',['volume','time'],'Q=V/t',fn($x)=>$x['volume']/$x['time']);
 $a('flow_area','fluids','Flow rate','flow_rate',['area','velocity'],'Q=Av',fn($x)=>$x['area']*$x['velocity']);
 $a('continuity','fluids','Continuity equation downstream speed','velocity2',['area1','velocity1','area2'],'v2=A1v1/A2',fn($x)=>$x['area1']*$x['velocity1']/$x['area2']);
 $a('torricelli','fluids','Torricelli speed','velocity',['gravity','height'],'v=sqrt(2gh)',fn($x)=>sqrt(2*$x['gravity']*$x['height']));
 // thermal
 $a('heat','thermal','Sensible heat','heat',['mass','specific_heat','temperature_change'],'Q=mcΔT',fn($x)=>$x['mass']*$x['specific_heat']*$x['temperature_change']);
 $a('latent_heat','thermal','Latent heat','heat',['mass','latent_heat'],'Q=mL',fn($x)=>$x['mass']*$x['latent_heat']);
 $a('celsius_kelvin','thermal','Celsius to Kelvin','temperature',['celsius'],'K=C+273.15',fn($x)=>$x['celsius']+273.15);
 $a('celsius_fahrenheit','thermal','Celsius to Fahrenheit','temperature',['celsius'],'F=9C/5+32',fn($x)=>9*$x['celsius']/5+32);
 $a('fahrenheit_celsius','thermal','Fahrenheit to Celsius','temperature',['fahrenheit'],'C=5(F-32)/9',fn($x)=>5*($x['fahrenheit']-32)/9);
 $a('linear_expansion','thermal','Linear expansion','length_change',['initial_length','expansion_coefficient','temperature_change'],'ΔL=αL0ΔT',fn($x)=>$x['expansion_coefficient']*$x['initial_length']*$x['temperature_change']);
 $a('ideal_gas','thermal','Ideal gas pressure','pressure',['moles','gas_constant','temperature','volume'],'P=nRT/V',fn($x)=>$x['moles']*$x['gas_constant']*$x['temperature']/$x['volume']);
 $a('boyle','thermal','Boyle law final pressure','pressure2',['pressure1','volume1','volume2'],'P2=P1V1/V2',fn($x)=>$x['pressure1']*$x['volume1']/$x['volume2']);
 $a('charles','thermal','Charles law final volume','volume2',['volume1','temperature1','temperature2'],'V2=V1T2/T1',fn($x)=>$x['volume1']*$x['temperature2']/$x['temperature1']);
 $a('combined_gas','thermal','Combined gas law final pressure','pressure2',['pressure1','volume1','temperature1','volume2','temperature2'],'P2=P1V1T2/(T1V2)',fn($x)=>$x['pressure1']*$x['volume1']*$x['temperature2']/($x['temperature1']*$x['volume2']));
 // electricity & magnetism
 $a('charge','electricity','Charge from current and time','charge',['current','time'],'Q=It',fn($x)=>$x['current']*$x['time']);
 $a('ohm_voltage','electricity','Ohm voltage','voltage',['current','resistance'],'U=IR',fn($x)=>$x['current']*$x['resistance']);
 $a('ohm_current','electricity','Ohm current','current',['voltage','resistance'],'I=U/R',fn($x)=>$x['voltage']/$x['resistance']);
 $a('ohm_resistance','electricity','Ohm resistance','resistance',['voltage','current'],'R=U/I',fn($x)=>$x['voltage']/$x['current']);
 $a('wire_resistance','electricity','Wire resistance','resistance',['resistivity','length','area'],'R=ρL/A',fn($x)=>$x['resistivity']*$x['length']/$x['area']);
 $a('electric_power','electricity','Electric power','power',['voltage','current'],'P=UI',fn($x)=>$x['voltage']*$x['current']);
 $a('electric_power_resistance','electricity','Electric power by I²R','power',['current','resistance'],'P=I²R',fn($x)=>$x['current']**2*$x['resistance']);
 $a('electric_energy','electricity','Electrical energy','energy',['power','time'],'E=Pt',fn($x)=>$x['power']*$x['time']);
 $a('joule_heat','electricity','Joule heating','heat',['current','resistance','time'],'Q=I²Rt',fn($x)=>$x['current']**2*$x['resistance']*$x['time']);
 $a('capacitance','electricity','Capacitance','capacitance',['charge','voltage'],'C=Q/U',fn($x)=>$x['charge']/$x['voltage']);
 $a('capacitor_energy','electricity','Capacitor energy','energy',['capacitance','voltage'],'E=CU²/2',fn($x)=>.5*$x['capacitance']*$x['voltage']**2);
 $a('magnetic_wire_force','magnetism','Force on current-carrying wire','force',['magnetic_field','current','length','angle'],'F=BILsinθ',fn($x)=>$x['magnetic_field']*$x['current']*$x['length']*sin($x['angle']*M_PI/180));
 $a('lorentz_force','magnetism','Lorentz force magnitude','force',['charge','velocity','magnetic_field','angle'],'F=qvBsinθ',fn($x)=>abs($x['charge'])*$x['velocity']*$x['magnetic_field']*sin($x['angle']*M_PI/180));
 $a('magnetic_flux','magnetism','Magnetic flux','magnetic_flux',['magnetic_field','area','angle'],'Φ=BAcosθ',fn($x)=>$x['magnetic_field']*$x['area']*cos($x['angle']*M_PI/180));
 $a('faraday_emf','magnetism','Induced EMF magnitude','emf',['flux_change','time'],'ε=|ΔΦ|/Δt',fn($x)=>abs($x['flux_change'])/$x['time']);
 // oscillations, waves, sound
 $a('spring_period','oscillations','Mass-spring period','period',['mass','spring_constant'],'T=2π√(m/k)',fn($x)=>2*M_PI*sqrt($x['mass']/$x['spring_constant']));
 $a('pendulum_period','oscillations','Simple pendulum period','period',['length','gravity'],'T=2π√(l/g)',fn($x)=>2*M_PI*sqrt($x['length']/$x['gravity']),['Small-angle approximation']);
 $a('frequency_period','waves','Frequency','frequency',['period'],'f=1/T',fn($x)=>1/$x['period']);
 $a('period_frequency','waves','Period','period',['frequency'],'T=1/f',fn($x)=>1/$x['frequency']);
 $a('wave_speed','waves','Wave speed','velocity',['wavelength','frequency'],'v=λf',fn($x)=>$x['wavelength']*$x['frequency']);
 $a('wavelength','waves','Wavelength','wavelength',['wave_speed','frequency'],'λ=v/f',fn($x)=>$x['wave_speed']/$x['frequency']);
 $a('sound_level','waves','Sound level','sound_level',['intensity','reference_intensity'],'L=10log10(I/I0)',fn($x)=>10*log10($x['intensity']/$x['reference_intensity']));
 $a('doppler','waves','Doppler sound frequency','frequency',['frequency','sound_speed','observer_speed','source_speed'],'f′=f(v+vo)/(v-vs)',fn($x)=>$x['frequency']*($x['sound_speed']+$x['observer_speed'])/($x['sound_speed']-$x['source_speed']),['Speeds positive toward each other']));
 // optics
 $a('snell','optics','Snell law refracted angle','angle',['index1','angle1','index2'],'θ2=asin(n1sinθ1/n2)',fn($x)=>asin(max(-1,min(1,$x['index1']*sin($x['angle1']*M_PI/180)/$x['index2'])))*180/M_PI);
 $a('thin_lens','optics','Thin-lens image distance','image_distance',['focal_length','object_distance'],'1/f=1/do+1/di',fn($x)=>1/(1/$x['focal_length']-1/$x['object_distance']));
 $a('lens_power','optics','Lens power','optical_power',['focal_length'],'D=1/f(m)',fn($x)=>1/$x['focal_length']);
 $a('magnification','optics','Magnification','magnification',['image_distance','object_distance'],'M=-di/do',fn($x)=>-$x['image_distance']/$x['object_distance']);
 $a('young_fringe','optics','Young fringe spacing','fringe_spacing',['wavelength','screen_distance','slit_separation'],'i=λL/d',fn($x)=>$x['wavelength']*$x['screen_distance']/$x['slit_separation']);
 // modern/nuclear/astronomy
 $a('photon_energy','modern','Photon energy','energy',['planck_constant','frequency'],'E=hf',fn($x)=>$x['planck_constant']*$x['frequency']);
 $a('photon_wavelength','modern','Photon energy by wavelength','energy',['planck_constant','speed_of_light','wavelength'],'E=hc/λ',fn($x)=>$x['planck_constant']*$x['speed_of_light']/$x['wavelength']);
 $a('debroglie','modern','De Broglie wavelength','wavelength',['planck_constant','momentum'],'λ=h/p',fn($x)=>$x['planck_constant']/$x['momentum']);
 $a('mass_energy','modern','Mass-energy','energy',['mass','speed_of_light'],'E=mc²',fn($x)=>$x['mass']*$x['speed_of_light']**2);
 $a('lorentz','modern','Lorentz factor','lorentz_factor',['velocity','speed_of_light'],'γ=1/sqrt(1-v²/c²)',fn($x)=>1/sqrt(1-($x['velocity']/$x['speed_of_light'])**2));
 $a('radioactive_decay','nuclear','Remaining radioactive amount','remaining_quantity',['initial_quantity','half_life','time'],'N=N0·2^(-t/T½)',fn($x)=>$x['initial_quantity']*2**(-$x['time']/$x['half_life']));
 $a('activity','nuclear','Radioactive activity','activity',['decay_constant','number_of_nuclei'],'A=λN',fn($x)=>$x['decay_constant']*$x['number_of_nuclei']);
 $a('half_life','nuclear','Half-life','half_life',['decay_constant'],'T½=ln2/λ',fn($x)=>log(2)/$x['decay_constant']);
 $a('binding_energy','nuclear','Binding energy','energy',['mass_defect','speed_of_light'],'E=Δmc²',fn($x)=>$x['mass_defect']*$x['speed_of_light']**2);
 $a('orbital_speed','astronomy','Circular orbital speed','velocity',['gravitational_constant','central_mass','orbital_radius'],'v=√(GM/r)',fn($x)=>sqrt($x['gravitational_constant']*$x['central_mass']/$x['orbital_radius']));
 $a('escape_speed','astronomy','Escape speed','velocity',['gravitational_constant','central_mass','radius'],'ve=√(2GM/r)',fn($x)=>sqrt(2*$x['gravitational_constant']*$x['central_mass']/$x['radius']));
 $a('kepler_period','astronomy','Orbital period','period',['gravitational_constant','central_mass','orbital_radius'],'T=2π√(r³/GM)',fn($x)=>2*M_PI*sqrt($x['orbital_radius']**3/($x['gravitational_constant']*$x['central_mass'])));
 $a('hubble_distance','astronomy','Hubble-law distance','distance',['recession_velocity','hubble_constant'],'d=v/H0',fn($x)=>$x['recession_velocity']/$x['hubble_constant']);
 // engineering
 $a('stress','engineering','Stress','stress',['force','area'],'σ=F/A',fn($x)=>$x['force']/$x['area']);
 $a('strain','engineering','Strain','strain',['length_change','initial_length'],'ε=ΔL/L0',fn($x)=>$x['length_change']/$x['initial_length']);
 $a('young_modulus','engineering','Young modulus','young_modulus',['stress','strain'],'E=σ/ε',fn($x)=>$x['stress']/$x['strain']);
 $a('torque','engineering','Torque','torque',['force','lever_arm','angle'],'τ=rFsinθ',fn($x)=>$x['lever_arm']*$x['force']*sin($x['angle']*M_PI/180));
 $a('hydraulic_force','engineering','Hydraulic force','force2',['force1','area1','area2'],'F2=F1A2/A1',fn($x)=>$x['force1']*$x['area2']/$x['area1']);
 // constants
 foreach(['gravity'=>9.80665,'speed_of_light'=>299792458.0,'gravitational_constant'=>6.67430e-11,'planck_constant'=>6.62607015e-34,'boltzmann_constant'=>1.380649e-23,'elementary_charge'=>1.602176634e-19,'gas_constant'=>8.314462618,'avogadro_constant'=>6.02214076e23,'coulomb_constant'=>8.9875517923e9,'stefan_boltzmann_constant'=>5.670374419e-8] as $key=>$value)$a('constant_'.$key,'constants','Constant '.$key,$key,[],$key.'='.$value,fn($x)=>$value);
 return $f;
}
function lac_physics_calculate(array $args): array {
 $r=lac_physics_formula_registry();$id=trim((string)($args['formula_id']??''));$target=trim((string)($args['target']??''));$v=(array)($args['values']??[]);$units=(array)($args['units']??[]);
 if(!empty($args['list_formulas']))return ['success'=>true,'formula_count'=>count($r),'formulas'=>array_map(fn($f)=>array_diff_key($f,['compute'=>true]),array_values($r))];
 $defaults=['gravity'=>9.80665,'speed_of_light'=>299792458.0,'gravitational_constant'=>6.67430e-11,'planck_constant'=>6.62607015e-34,'boltzmann_constant'=>1.380649e-23,'elementary_charge'=>1.602176634e-19,'gas_constant'=>8.314462618,'avogadro_constant'=>6.02214076e23,'coulomb_constant'=>8.9875517923e9,'stefan_boltzmann_constant'=>5.670374419e-8];
 foreach($defaults as $k=>$val)if(!array_key_exists($k,$v))$v[$k]=$val;
 if($id!==''){if(!isset($r[$id]))throw new InvalidArgumentException('Unknown formula_id; call list_formulas=true to inspect the built-in catalog.');$m=$r[$id];}
 else{$matches=[];foreach($r as $f)if(($target===''||strcasecmp($target,$f['target'])===0||strcasecmp($target,$f['name'])===0)&&!array_diff($f['inputs'],array_keys($v)))$matches[]=$f;if(!$matches)throw new InvalidArgumentException('No matching formula. Provide formula_id or target plus all required numerical inputs.');$m=$matches[0];}
 $missing=array_values(array_diff($m['inputs'],array_keys($v)));if($missing)throw new InvalidArgumentException('Missing inputs: '.implode(', ',$missing));
 $x=[];foreach($m['inputs'] as $k){if(!is_numeric($v[$k])||!is_finite((float)$v[$k]))throw new InvalidArgumentException('Input '.$k.' must be finite numeric.');$x[$k]=(float)$v[$k];if(isset($units[$k]))$x[$k]=lac_physics_convert_unit($k,$x[$k],(string)$units[$k]);}
 $res=($m['compute'])($x);if(!is_finite((float)$res))throw new DomainException('Non-finite result. Check units, denominator and physical conditions.');
 return ['success'=>true,'formula_id'=>$m['id'],'domain'=>$m['domain'],'formula_name'=>$m['name'],'target'=>$m['target'],'equation'=>$m['equation'],'inputs_si'=>$x,'result'=>(float)$res,'output_unit'=>lac_physics_output_unit($m['target']),'assumptions'=>$m['assumptions'],'formula_count'=>count($r)];
}
function lac_physics_convert_unit(string $k,float $v,string $u): float {
 $map=['time'=>['s'=>1,'sec'=>1,'min'=>60,'h'=>3600,'ms'=>.001],'distance'=>['m'=>1,'km'=>1000,'cm'=>.01,'mm'=>.001,'ft'=>.3048,'mi'=>1609.344],'height'=>['m'=>1,'km'=>1000,'cm'=>.01,'mm'=>.001,'ft'=>.3048],'radius'=>['m'=>1,'km'=>1000,'cm'=>.01,'mm'=>.001],'mass'=>['kg'=>1,'g'=>.001,'mg'=>1e-6,'tonne'=>1000],'velocity'=>['m/s'=>1,'km/h'=>1/3.6,'mph'=>.44704,'ft/s'=>.3048],'initial_velocity'=>['m/s'=>1,'km/h'=>1/3.6],'final_velocity'=>['m/s'=>1,'km/h'=>1/3.6],'acceleration'=>['m/s2'=>1,'m/s^2'=>1,'g'=>9.80665],'force'=>['N'=>1,'kN'=>1000,'lbf'=>4.448221615],'pressure'=>['Pa'=>1,'kPa'=>1000,'bar'=>100000,'atm'=>101325,'psi'=>6894.757293],'energy'=>['J'=>1,'kJ'=>1000,'cal'=>4.184,'kcal'=>4184,'kWh'=>3600000,'eV'=>1.602176634e-19],'power'=>['W'=>1,'kW'=>1000,'hp'=>745.699872],'volume'=>['m3'=>1,'m^3'=>1,'L'=>.001,'ml'=>1e-6],'area'=>['m2'=>1,'m^2'=>1,'cm2'=>1e-4,'mm2'=>1e-6],'frequency'=>['Hz'=>1,'kHz'=>1000,'MHz'=>1e6],'wavelength'=>['m'=>1,'nm'=>1e-9,'um'=>1e-6,'mm'=>1e-3],'voltage'=>['V'=>1,'mV'=>.001,'kV'=>1000],'current'=>['A'=>1,'mA'=>.001],'resistance'=>['ohm'=>1,'Ω'=>1,'kohm'=>1000,'kΩ'=>1000],'density'=>['kg/m3'=>1,'g/cm3'=>1000],'specific_heat'=>['J/(kg K)'=>1,'J/kgK'=>1,'kJ/(kg K)'=>1000],'angle'=>['deg'=>1,'degree'=>1,'rad'=>180/M_PI],'angular_velocity'=>['rad/s'=>1,'rpm'=>2*M_PI/60],'spring_constant'=>['N/m'=>1,'N/cm'=>100],'magnetic_field'=>['T'=>1,'mT'=>.001],'charge'=>['C'=>1,'mC'=>.001,'uC'=>1e-6,'nC'=>1e-9],'mass_defect'=>['kg'=>1,'u'=>1.66053906660e-27],'lever_arm'=>['m'=>1,'cm'=>.01,'mm'=>.001]];
 if(!isset($map[$k]))return $v;if(!isset($map[$k][$u]))throw new InvalidArgumentException('Unsupported unit '.$u.' for '.$k);return $v*$map[$k][$u];
}
function lac_physics_output_unit(string $t): string {return ['velocity'=>'m/s','acceleration'=>'m/s²','force'=>'N','force2'=>'N','distance'=>'m','range'=>'m','height'=>'m','time'=>'s','period'=>'s','frequency'=>'Hz','energy'=>'J','work'=>'J','power'=>'W','pressure'=>'Pa','density'=>'kg/m³','flow_rate'=>'m³/s','heat'=>'J','temperature'=>'K/°C/°F','charge'=>'C','voltage'=>'V','current'=>'A','resistance'=>'Ω','capacitance'=>'F','momentum'=>'kg·m/s','impulse'=>'N·s','angular_velocity'=>'rad/s','angular_acceleration'=>'rad/s²','magnetic_flux'=>'Wb','emf'=>'V','wavelength'=>'m','image_distance'=>'m','optical_power'=>'D','fringe_spacing'=>'m','sound_level'=>'dB','angle'=>'degree','efficiency'=>'%','stress'=>'Pa','strain'=>'dimensionless','young_modulus'=>'Pa','torque'=>'N·m','gravity'=>'m/s²','speed_of_light'=>'m/s','gravitational_constant'=>'m³/(kg·s²)','planck_constant'=>'J·s','boltzmann_constant'=>'J/K','gas_constant'=>'J/(mol·K)','lorentz_factor'=>'dimensionless','half_life'=>'s','activity'=>'Bq','remaining_quantity'=>'same as input','magnetic_flux'=>'Wb'][$t]??'SI';
}
