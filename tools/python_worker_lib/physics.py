"""Physics calculation domain; migrated from the legacy PHP formula engine."""
from .common import *
import math

FORMULAS = [
  {
    "id": "velocity",
    "domain": "kinematics",
    "name": "Average velocity",
    "target": "velocity",
    "inputs": [
      "distance",
      "time"
    ],
    "equation": "v=s/t",
    "expr": "x[\"distance\"]/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "distance_uniform",
    "domain": "kinematics",
    "name": "Uniform-motion distance",
    "target": "distance",
    "inputs": [
      "velocity",
      "time"
    ],
    "equation": "s=vt",
    "expr": "x[\"velocity\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "acceleration",
    "domain": "kinematics",
    "name": "Acceleration",
    "target": "acceleration",
    "inputs": [
      "final_velocity",
      "initial_velocity",
      "time"
    ],
    "equation": "a=(v-v0)/t",
    "expr": "(x[\"final_velocity\"]-x[\"initial_velocity\"])/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "final_velocity",
    "domain": "kinematics",
    "name": "Final velocity",
    "target": "velocity",
    "inputs": [
      "initial_velocity",
      "acceleration",
      "time"
    ],
    "equation": "v=v0+at",
    "expr": "x[\"initial_velocity\"]+x[\"acceleration\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "distance_accelerated",
    "domain": "kinematics",
    "name": "Distance with constant acceleration",
    "target": "distance",
    "inputs": [
      "initial_velocity",
      "time",
      "acceleration"
    ],
    "equation": "s=v0t+at²/2",
    "expr": "x[\"initial_velocity\"]*x[\"time\"]+.5*x[\"acceleration\"]*x[\"time\"]**2)",
    "assumptions": []
  },
  {
    "id": "velocity_distance",
    "domain": "kinematics",
    "name": "Velocity from displacement",
    "target": "velocity",
    "inputs": [
      "initial_velocity",
      "acceleration",
      "distance"
    ],
    "equation": "v=sqrt(v0²+2as)",
    "expr": "math.sqrt(max(0,x[\"initial_velocity\"]**2+2*x[\"acceleration\"]*x[\"distance\"])))",
    "assumptions": []
  },
  {
    "id": "freefall_distance",
    "domain": "kinematics",
    "name": "Free-fall distance",
    "target": "distance",
    "inputs": [
      "gravity",
      "time"
    ],
    "equation": "h=gt²/2",
    "expr": ".5*x[\"gravity\"]*x[\"time\"]**2)",
    "assumptions": []
  },
  {
    "id": "freefall_velocity",
    "domain": "kinematics",
    "name": "Free-fall speed",
    "target": "velocity",
    "inputs": [
      "gravity",
      "time"
    ],
    "equation": "v=gt",
    "expr": "x[\"gravity\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "freefall_time",
    "domain": "kinematics",
    "name": "Free-fall time",
    "target": "time",
    "inputs": [
      "height",
      "gravity"
    ],
    "equation": "t=sqrt(2h/g)",
    "expr": "math.sqrt(2*x[\"height\"]/x[\"gravity\"]))",
    "assumptions": []
  },
  {
    "id": "projectile_range",
    "domain": "kinematics",
    "name": "Projectile range (same elevation)",
    "target": "range",
    "inputs": [
      "initial_speed",
      "angle",
      "gravity"
    ],
    "equation": "R=v0²sin(2θ)/g",
    "expr": "x[\"initial_speed\"]**2*math.sin(2*x[\"angle\"]*math.pi/180)/x[\"gravity\"]",
    "assumptions": [
      "Same launch/landing elevation; angle in degrees"
    ]
  },
  {
    "id": "projectile_height",
    "domain": "kinematics",
    "name": "Projectile maximum height",
    "target": "height",
    "inputs": [
      "initial_speed",
      "angle",
      "gravity"
    ],
    "equation": "H=v0²sin²θ/(2g)",
    "expr": "x[\"initial_speed\"]**2*math.sin(x[\"angle\"]*math.pi/180)**2/(2*x[\"gravity\"])",
    "assumptions": [
      "Angle in degrees"
    ]
  },
  {
    "id": "force",
    "domain": "mechanics",
    "name": "Newton second law",
    "target": "force",
    "inputs": [
      "mass",
      "acceleration"
    ],
    "equation": "F=ma",
    "expr": "x[\"mass\"]*x[\"acceleration\"])",
    "assumptions": []
  },
  {
    "id": "weight",
    "domain": "mechanics",
    "name": "Weight",
    "target": "force",
    "inputs": [
      "mass",
      "gravity"
    ],
    "equation": "W=mg",
    "expr": "x[\"mass\"]*x[\"gravity\"])",
    "assumptions": []
  },
  {
    "id": "friction",
    "domain": "mechanics",
    "name": "Friction force",
    "target": "force",
    "inputs": [
      "coefficient",
      "normal_force"
    ],
    "equation": "F=μN",
    "expr": "x[\"coefficient\"]*x[\"normal_force\"])",
    "assumptions": []
  },
  {
    "id": "spring_force",
    "domain": "mechanics",
    "name": "Spring force magnitude",
    "target": "force",
    "inputs": [
      "spring_constant",
      "displacement"
    ],
    "equation": "F=kx",
    "expr": "x[\"spring_constant\"]*abs(x[\"displacement\"]))",
    "assumptions": []
  },
  {
    "id": "centripetal_force",
    "domain": "mechanics",
    "name": "Centripetal force",
    "target": "force",
    "inputs": [
      "mass",
      "velocity",
      "radius"
    ],
    "equation": "Fc=mv²/r",
    "expr": "x[\"mass\"]*x[\"velocity\"]**2/x[\"radius\"])",
    "assumptions": []
  },
  {
    "id": "momentum",
    "domain": "mechanics",
    "name": "Momentum",
    "target": "momentum",
    "inputs": [
      "mass",
      "velocity"
    ],
    "equation": "p=mv",
    "expr": "x[\"mass\"]*x[\"velocity\"])",
    "assumptions": []
  },
  {
    "id": "impulse",
    "domain": "mechanics",
    "name": "Impulse",
    "target": "impulse",
    "inputs": [
      "force",
      "time"
    ],
    "equation": "J=FΔt",
    "expr": "x[\"force\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "force_momentum",
    "domain": "mechanics",
    "name": "Average force from momentum change",
    "target": "force",
    "inputs": [
      "delta_momentum",
      "time"
    ],
    "equation": "F=Δp/Δt",
    "expr": "x[\"delta_momentum\"]/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "gravity_force",
    "domain": "mechanics",
    "name": "Universal gravitation",
    "target": "force",
    "inputs": [
      "mass1",
      "mass2",
      "distance",
      "gravitational_constant"
    ],
    "equation": "F=Gm1m2/r²",
    "expr": "x[\"gravitational_constant\"]*x[\"mass1\"]*x[\"mass2\"]/x[\"distance\"]**2)",
    "assumptions": []
  },
  {
    "id": "gravity_field",
    "domain": "mechanics",
    "name": "Gravity field strength",
    "target": "gravity",
    "inputs": [
      "central_mass",
      "radius",
      "gravitational_constant"
    ],
    "equation": "g=GM/r²",
    "expr": "x[\"gravitational_constant\"]*x[\"central_mass\"]/x[\"radius\"]**2)",
    "assumptions": []
  },
  {
    "id": "work",
    "domain": "energy",
    "name": "Work",
    "target": "work",
    "inputs": [
      "force",
      "distance",
      "angle"
    ],
    "equation": "W=Fs cosθ",
    "expr": "x[\"force\"]*x[\"distance\"]*math.cos(x[\"angle\"]*math.pi/180)",
    "assumptions": [
      "Angle in degrees"
    ]
  },
  {
    "id": "kinetic_energy",
    "domain": "energy",
    "name": "Kinetic energy",
    "target": "energy",
    "inputs": [
      "mass",
      "velocity"
    ],
    "equation": "E=mv²/2",
    "expr": ".5*x[\"mass\"]*x[\"velocity\"]**2)",
    "assumptions": []
  },
  {
    "id": "potential_energy",
    "domain": "energy",
    "name": "Gravitational potential energy",
    "target": "energy",
    "inputs": [
      "mass",
      "gravity",
      "height"
    ],
    "equation": "E=mgh",
    "expr": "x[\"mass\"]*x[\"gravity\"]*x[\"height\"])",
    "assumptions": []
  },
  {
    "id": "spring_energy",
    "domain": "energy",
    "name": "Elastic potential energy",
    "target": "energy",
    "inputs": [
      "spring_constant",
      "displacement"
    ],
    "equation": "E=kx²/2",
    "expr": ".5*x[\"spring_constant\"]*x[\"displacement\"]**2)",
    "assumptions": []
  },
  {
    "id": "power",
    "domain": "energy",
    "name": "Power",
    "target": "power",
    "inputs": [
      "work",
      "time"
    ],
    "equation": "P=W/t",
    "expr": "x[\"work\"]/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "power_fv",
    "domain": "energy",
    "name": "Power from force and speed",
    "target": "power",
    "inputs": [
      "force",
      "velocity"
    ],
    "equation": "P=Fv",
    "expr": "x[\"force\"]*x[\"velocity\"])",
    "assumptions": []
  },
  {
    "id": "efficiency",
    "domain": "energy",
    "name": "Efficiency percent",
    "target": "efficiency",
    "inputs": [
      "useful_output",
      "input"
    ],
    "equation": "η=100 useful/input",
    "expr": "100*x[\"useful_output\"]/x[\"input\"])",
    "assumptions": []
  },
  {
    "id": "angular_velocity",
    "domain": "rotation",
    "name": "Angular velocity",
    "target": "angular_velocity",
    "inputs": [
      "angle",
      "time"
    ],
    "equation": "ω=θ/t",
    "expr": "x[\"angle\"]/x[\"time\"]",
    "assumptions": [
      "Angle in radians"
    ]
  },
  {
    "id": "angular_acceleration",
    "domain": "rotation",
    "name": "Angular acceleration",
    "target": "angular_acceleration",
    "inputs": [
      "final_angular_velocity",
      "initial_angular_velocity",
      "time"
    ],
    "equation": "α=Δω/t",
    "expr": "(x[\"final_angular_velocity\"]-x[\"initial_angular_velocity\"])/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "centripetal_acceleration",
    "domain": "rotation",
    "name": "Centripetal acceleration",
    "target": "acceleration",
    "inputs": [
      "velocity",
      "radius"
    ],
    "equation": "a=v²/r",
    "expr": "x[\"velocity\"]**2/x[\"radius\"])",
    "assumptions": []
  },
  {
    "id": "linear_speed",
    "domain": "rotation",
    "name": "Linear speed from angular speed",
    "target": "velocity",
    "inputs": [
      "angular_velocity",
      "radius"
    ],
    "equation": "v=ωr",
    "expr": "x[\"angular_velocity\"]*x[\"radius\"])",
    "assumptions": []
  },
  {
    "id": "density",
    "domain": "fluids",
    "name": "Density",
    "target": "density",
    "inputs": [
      "mass",
      "volume"
    ],
    "equation": "ρ=m/V",
    "expr": "x[\"mass\"]/x[\"volume\"])",
    "assumptions": []
  },
  {
    "id": "pressure",
    "domain": "fluids",
    "name": "Pressure",
    "target": "pressure",
    "inputs": [
      "force",
      "area"
    ],
    "equation": "p=F/A",
    "expr": "x[\"force\"]/x[\"area\"])",
    "assumptions": []
  },
  {
    "id": "hydrostatic",
    "domain": "fluids",
    "name": "Hydrostatic pressure",
    "target": "pressure",
    "inputs": [
      "density",
      "gravity",
      "depth"
    ],
    "equation": "p=ρgh",
    "expr": "x[\"density\"]*x[\"gravity\"]*x[\"depth\"])",
    "assumptions": []
  },
  {
    "id": "buoyancy",
    "domain": "fluids",
    "name": "Archimedes force",
    "target": "force",
    "inputs": [
      "fluid_density",
      "gravity",
      "displaced_volume"
    ],
    "equation": "FA=ρgV",
    "expr": "x[\"fluid_density\"]*x[\"gravity\"]*x[\"displaced_volume\"])",
    "assumptions": []
  },
  {
    "id": "flow_rate",
    "domain": "fluids",
    "name": "Volumetric flow rate",
    "target": "flow_rate",
    "inputs": [
      "volume",
      "time"
    ],
    "equation": "Q=V/t",
    "expr": "x[\"volume\"]/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "flow_area",
    "domain": "fluids",
    "name": "Flow rate",
    "target": "flow_rate",
    "inputs": [
      "area",
      "velocity"
    ],
    "equation": "Q=Av",
    "expr": "x[\"area\"]*x[\"velocity\"])",
    "assumptions": []
  },
  {
    "id": "continuity",
    "domain": "fluids",
    "name": "Continuity equation downstream speed",
    "target": "velocity2",
    "inputs": [
      "area1",
      "velocity1",
      "area2"
    ],
    "equation": "v2=A1v1/A2",
    "expr": "x[\"area1\"]*x[\"velocity1\"]/x[\"area2\"])",
    "assumptions": []
  },
  {
    "id": "torricelli",
    "domain": "fluids",
    "name": "Torricelli speed",
    "target": "velocity",
    "inputs": [
      "gravity",
      "height"
    ],
    "equation": "v=sqrt(2gh)",
    "expr": "math.sqrt(2*x[\"gravity\"]*x[\"height\"]))",
    "assumptions": []
  },
  {
    "id": "heat",
    "domain": "thermal",
    "name": "Sensible heat",
    "target": "heat",
    "inputs": [
      "mass",
      "specific_heat",
      "temperature_change"
    ],
    "equation": "Q=mcΔT",
    "expr": "x[\"mass\"]*x[\"specific_heat\"]*x[\"temperature_change\"])",
    "assumptions": []
  },
  {
    "id": "latent_heat",
    "domain": "thermal",
    "name": "Latent heat",
    "target": "heat",
    "inputs": [
      "mass",
      "latent_heat"
    ],
    "equation": "Q=mL",
    "expr": "x[\"mass\"]*x[\"latent_heat\"])",
    "assumptions": []
  },
  {
    "id": "celsius_kelvin",
    "domain": "thermal",
    "name": "Celsius to Kelvin",
    "target": "temperature",
    "inputs": [
      "celsius"
    ],
    "equation": "K=C+273.15",
    "expr": "x[\"celsius\"]+273.15)",
    "assumptions": []
  },
  {
    "id": "celsius_fahrenheit",
    "domain": "thermal",
    "name": "Celsius to Fahrenheit",
    "target": "temperature",
    "inputs": [
      "celsius"
    ],
    "equation": "F=9C/5+32",
    "expr": "9*x[\"celsius\"]/5+32)",
    "assumptions": []
  },
  {
    "id": "fahrenheit_celsius",
    "domain": "thermal",
    "name": "Fahrenheit to Celsius",
    "target": "temperature",
    "inputs": [
      "fahrenheit"
    ],
    "equation": "C=5(F-32)/9",
    "expr": "5*(x[\"fahrenheit\"]-32)/9)",
    "assumptions": []
  },
  {
    "id": "linear_expansion",
    "domain": "thermal",
    "name": "Linear expansion",
    "target": "length_change",
    "inputs": [
      "initial_length",
      "expansion_coefficient",
      "temperature_change"
    ],
    "equation": "ΔL=αL0ΔT",
    "expr": "x[\"expansion_coefficient\"]*x[\"initial_length\"]*x[\"temperature_change\"])",
    "assumptions": []
  },
  {
    "id": "ideal_gas",
    "domain": "thermal",
    "name": "Ideal gas pressure",
    "target": "pressure",
    "inputs": [
      "moles",
      "gas_constant",
      "temperature",
      "volume"
    ],
    "equation": "P=nRT/V",
    "expr": "x[\"moles\"]*x[\"gas_constant\"]*x[\"temperature\"]/x[\"volume\"])",
    "assumptions": []
  },
  {
    "id": "boyle",
    "domain": "thermal",
    "name": "Boyle law final pressure",
    "target": "pressure2",
    "inputs": [
      "pressure1",
      "volume1",
      "volume2"
    ],
    "equation": "P2=P1V1/V2",
    "expr": "x[\"pressure1\"]*x[\"volume1\"]/x[\"volume2\"])",
    "assumptions": []
  },
  {
    "id": "charles",
    "domain": "thermal",
    "name": "Charles law final volume",
    "target": "volume2",
    "inputs": [
      "volume1",
      "temperature1",
      "temperature2"
    ],
    "equation": "V2=V1T2/T1",
    "expr": "x[\"volume1\"]*x[\"temperature2\"]/x[\"temperature1\"])",
    "assumptions": []
  },
  {
    "id": "combined_gas",
    "domain": "thermal",
    "name": "Combined gas law final pressure",
    "target": "pressure2",
    "inputs": [
      "pressure1",
      "volume1",
      "temperature1",
      "volume2",
      "temperature2"
    ],
    "equation": "P2=P1V1T2/(T1V2)",
    "expr": "x[\"pressure1\"]*x[\"volume1\"]*x[\"temperature2\"]/(x[\"temperature1\"]*x[\"volume2\"]))",
    "assumptions": []
  },
  {
    "id": "charge",
    "domain": "electricity",
    "name": "Charge from current and time",
    "target": "charge",
    "inputs": [
      "current",
      "time"
    ],
    "equation": "Q=It",
    "expr": "x[\"current\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "ohm_voltage",
    "domain": "electricity",
    "name": "Ohm voltage",
    "target": "voltage",
    "inputs": [
      "current",
      "resistance"
    ],
    "equation": "U=IR",
    "expr": "x[\"current\"]*x[\"resistance\"])",
    "assumptions": []
  },
  {
    "id": "ohm_current",
    "domain": "electricity",
    "name": "Ohm current",
    "target": "current",
    "inputs": [
      "voltage",
      "resistance"
    ],
    "equation": "I=U/R",
    "expr": "x[\"voltage\"]/x[\"resistance\"])",
    "assumptions": []
  },
  {
    "id": "ohm_resistance",
    "domain": "electricity",
    "name": "Ohm resistance",
    "target": "resistance",
    "inputs": [
      "voltage",
      "current"
    ],
    "equation": "R=U/I",
    "expr": "x[\"voltage\"]/x[\"current\"])",
    "assumptions": []
  },
  {
    "id": "wire_resistance",
    "domain": "electricity",
    "name": "Wire resistance",
    "target": "resistance",
    "inputs": [
      "resistivity",
      "length",
      "area"
    ],
    "equation": "R=ρL/A",
    "expr": "x[\"resistivity\"]*x[\"length\"]/x[\"area\"])",
    "assumptions": []
  },
  {
    "id": "electric_power",
    "domain": "electricity",
    "name": "Electric power",
    "target": "power",
    "inputs": [
      "voltage",
      "current"
    ],
    "equation": "P=UI",
    "expr": "x[\"voltage\"]*x[\"current\"])",
    "assumptions": []
  },
  {
    "id": "electric_power_resistance",
    "domain": "electricity",
    "name": "Electric power by I²R",
    "target": "power",
    "inputs": [
      "current",
      "resistance"
    ],
    "equation": "P=I²R",
    "expr": "x[\"current\"]**2*x[\"resistance\"])",
    "assumptions": []
  },
  {
    "id": "electric_energy",
    "domain": "electricity",
    "name": "Electrical energy",
    "target": "energy",
    "inputs": [
      "power",
      "time"
    ],
    "equation": "E=Pt",
    "expr": "x[\"power\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "joule_heat",
    "domain": "electricity",
    "name": "Joule heating",
    "target": "heat",
    "inputs": [
      "current",
      "resistance",
      "time"
    ],
    "equation": "Q=I²Rt",
    "expr": "x[\"current\"]**2*x[\"resistance\"]*x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "capacitance",
    "domain": "electricity",
    "name": "Capacitance",
    "target": "capacitance",
    "inputs": [
      "charge",
      "voltage"
    ],
    "equation": "C=Q/U",
    "expr": "x[\"charge\"]/x[\"voltage\"])",
    "assumptions": []
  },
  {
    "id": "capacitor_energy",
    "domain": "electricity",
    "name": "Capacitor energy",
    "target": "energy",
    "inputs": [
      "capacitance",
      "voltage"
    ],
    "equation": "E=CU²/2",
    "expr": ".5*x[\"capacitance\"]*x[\"voltage\"]**2)",
    "assumptions": []
  },
  {
    "id": "magnetic_wire_force",
    "domain": "magnetism",
    "name": "Force on current-carrying wire",
    "target": "force",
    "inputs": [
      "magnetic_field",
      "current",
      "length",
      "angle"
    ],
    "equation": "F=BILsinθ",
    "expr": "x[\"magnetic_field\"]*x[\"current\"]*x[\"length\"]*math.sin(x[\"angle\"]*math.pi/180))",
    "assumptions": []
  },
  {
    "id": "lorentz_force",
    "domain": "magnetism",
    "name": "Lorentz force magnitude",
    "target": "force",
    "inputs": [
      "charge",
      "velocity",
      "magnetic_field",
      "angle"
    ],
    "equation": "F=qvBsinθ",
    "expr": "abs(x[\"charge\"])*x[\"velocity\"]*x[\"magnetic_field\"]*math.sin(x[\"angle\"]*math.pi/180))",
    "assumptions": []
  },
  {
    "id": "magnetic_flux",
    "domain": "magnetism",
    "name": "Magnetic flux",
    "target": "magnetic_flux",
    "inputs": [
      "magnetic_field",
      "area",
      "angle"
    ],
    "equation": "Φ=BAcosθ",
    "expr": "x[\"magnetic_field\"]*x[\"area\"]*math.cos(x[\"angle\"]*math.pi/180))",
    "assumptions": []
  },
  {
    "id": "faraday_emf",
    "domain": "magnetism",
    "name": "Induced EMF magnitude",
    "target": "emf",
    "inputs": [
      "flux_change",
      "time"
    ],
    "equation": "ε=|ΔΦ|/Δt",
    "expr": "abs(x[\"flux_change\"])/x[\"time\"])",
    "assumptions": []
  },
  {
    "id": "spring_period",
    "domain": "oscillations",
    "name": "Mass-spring period",
    "target": "period",
    "inputs": [
      "mass",
      "spring_constant"
    ],
    "equation": "T=2π√(m/k)",
    "expr": "2*math.pi*math.sqrt(x[\"mass\"]/x[\"spring_constant\"]))",
    "assumptions": []
  },
  {
    "id": "pendulum_period",
    "domain": "oscillations",
    "name": "Simple pendulum period",
    "target": "period",
    "inputs": [
      "length",
      "gravity"
    ],
    "equation": "T=2π√(l/g)",
    "expr": "2*math.pi*math.sqrt(x[\"length\"]/x[\"gravity\"])",
    "assumptions": [
      "Small-angle approximation"
    ]
  },
  {
    "id": "frequency_period",
    "domain": "waves",
    "name": "Frequency",
    "target": "frequency",
    "inputs": [
      "period"
    ],
    "equation": "f=1/T",
    "expr": "1/x[\"period\"])",
    "assumptions": []
  },
  {
    "id": "period_frequency",
    "domain": "waves",
    "name": "Period",
    "target": "period",
    "inputs": [
      "frequency"
    ],
    "equation": "T=1/f",
    "expr": "1/x[\"frequency\"])",
    "assumptions": []
  },
  {
    "id": "wave_speed",
    "domain": "waves",
    "name": "Wave speed",
    "target": "velocity",
    "inputs": [
      "wavelength",
      "frequency"
    ],
    "equation": "v=λf",
    "expr": "x[\"wavelength\"]*x[\"frequency\"])",
    "assumptions": []
  },
  {
    "id": "wavelength",
    "domain": "waves",
    "name": "Wavelength",
    "target": "wavelength",
    "inputs": [
      "wave_speed",
      "frequency"
    ],
    "equation": "λ=v/f",
    "expr": "x[\"wave_speed\"]/x[\"frequency\"])",
    "assumptions": []
  },
  {
    "id": "sound_level",
    "domain": "waves",
    "name": "Sound level",
    "target": "sound_level",
    "inputs": [
      "intensity",
      "reference_intensity"
    ],
    "equation": "L=10log10(I/I0)",
    "expr": "10*math.log10(x[\"intensity\"]/x[\"reference_intensity\"]))",
    "assumptions": []
  },
  {
    "id": "doppler",
    "domain": "waves",
    "name": "Doppler sound frequency",
    "target": "frequency",
    "inputs": [
      "frequency",
      "sound_speed",
      "observer_speed",
      "source_speed"
    ],
    "equation": "f′=f(v+vo)/(v-vs)",
    "expr": "x[\"frequency\"]*(x[\"sound_speed\"]+x[\"observer_speed\"])/(x[\"sound_speed\"]-x[\"source_speed\"])",
    "assumptions": [
      "Speeds positive toward each other"
    ]
  },
  {
    "id": "snell",
    "domain": "optics",
    "name": "Snell law refracted angle",
    "target": "angle",
    "inputs": [
      "index1",
      "angle1",
      "index2"
    ],
    "equation": "θ2=asin(n1sinθ1/n2)",
    "expr": "math.asin(max(-1,min(1,x[\"index1\"]*math.sin(x[\"angle1\"]*math.pi/180)/x[\"index2\"])))*180/math.pi)",
    "assumptions": []
  },
  {
    "id": "thin_lens",
    "domain": "optics",
    "name": "Thin-lens image distance",
    "target": "image_distance",
    "inputs": [
      "focal_length",
      "object_distance"
    ],
    "equation": "1/f=1/do+1/di",
    "expr": "1/(1/x[\"focal_length\"]-1/x[\"object_distance\"]))",
    "assumptions": []
  },
  {
    "id": "lens_power",
    "domain": "optics",
    "name": "Lens power",
    "target": "optical_power",
    "inputs": [
      "focal_length"
    ],
    "equation": "D=1/f(m)",
    "expr": "1/x[\"focal_length\"])",
    "assumptions": []
  },
  {
    "id": "magnification",
    "domain": "optics",
    "name": "Magnification",
    "target": "magnification",
    "inputs": [
      "image_distance",
      "object_distance"
    ],
    "equation": "M=-di/do",
    "expr": "-x[\"image_distance\"]/x[\"object_distance\"])",
    "assumptions": []
  },
  {
    "id": "young_fringe",
    "domain": "optics",
    "name": "Young fringe spacing",
    "target": "fringe_spacing",
    "inputs": [
      "wavelength",
      "screen_distance",
      "slit_separation"
    ],
    "equation": "i=λL/d",
    "expr": "x[\"wavelength\"]*x[\"screen_distance\"]/x[\"slit_separation\"])",
    "assumptions": []
  },
  {
    "id": "photon_energy",
    "domain": "modern",
    "name": "Photon energy",
    "target": "energy",
    "inputs": [
      "planck_constant",
      "frequency"
    ],
    "equation": "E=hf",
    "expr": "x[\"planck_constant\"]*x[\"frequency\"])",
    "assumptions": []
  },
  {
    "id": "photon_wavelength",
    "domain": "modern",
    "name": "Photon energy by wavelength",
    "target": "energy",
    "inputs": [
      "planck_constant",
      "speed_of_light",
      "wavelength"
    ],
    "equation": "E=hc/λ",
    "expr": "x[\"planck_constant\"]*x[\"speed_of_light\"]/x[\"wavelength\"])",
    "assumptions": []
  },
  {
    "id": "debroglie",
    "domain": "modern",
    "name": "De Broglie wavelength",
    "target": "wavelength",
    "inputs": [
      "planck_constant",
      "momentum"
    ],
    "equation": "λ=h/p",
    "expr": "x[\"planck_constant\"]/x[\"momentum\"])",
    "assumptions": []
  },
  {
    "id": "mass_energy",
    "domain": "modern",
    "name": "Mass-energy",
    "target": "energy",
    "inputs": [
      "mass",
      "speed_of_light"
    ],
    "equation": "E=mc²",
    "expr": "x[\"mass\"]*x[\"speed_of_light\"]**2)",
    "assumptions": []
  },
  {
    "id": "lorentz",
    "domain": "modern",
    "name": "Lorentz factor",
    "target": "lorentz_factor",
    "inputs": [
      "velocity",
      "speed_of_light"
    ],
    "equation": "γ=1/sqrt(1-v²/c²)",
    "expr": "1/math.sqrt(1-(x[\"velocity\"]/x[\"speed_of_light\"])**2))",
    "assumptions": []
  },
  {
    "id": "radioactive_decay",
    "domain": "nuclear",
    "name": "Remaining radioactive amount",
    "target": "remaining_quantity",
    "inputs": [
      "initial_quantity",
      "half_life",
      "time"
    ],
    "equation": "N=N0·2^(-t/T½)",
    "expr": "x[\"initial_quantity\"]*2**(-x[\"time\"]/x[\"half_life\"]))",
    "assumptions": []
  },
  {
    "id": "activity",
    "domain": "nuclear",
    "name": "Radioactive activity",
    "target": "activity",
    "inputs": [
      "decay_constant",
      "number_of_nuclei"
    ],
    "equation": "A=λN",
    "expr": "x[\"decay_constant\"]*x[\"number_of_nuclei\"])",
    "assumptions": []
  },
  {
    "id": "half_life",
    "domain": "nuclear",
    "name": "Half-life",
    "target": "half_life",
    "inputs": [
      "decay_constant"
    ],
    "equation": "T½=ln2/λ",
    "expr": "math.log(2)/x[\"decay_constant\"])",
    "assumptions": []
  },
  {
    "id": "binding_energy",
    "domain": "nuclear",
    "name": "Binding energy",
    "target": "energy",
    "inputs": [
      "mass_defect",
      "speed_of_light"
    ],
    "equation": "E=Δmc²",
    "expr": "x[\"mass_defect\"]*x[\"speed_of_light\"]**2)",
    "assumptions": []
  },
  {
    "id": "orbital_speed",
    "domain": "astronomy",
    "name": "Circular orbital speed",
    "target": "velocity",
    "inputs": [
      "gravitational_constant",
      "central_mass",
      "orbital_radius"
    ],
    "equation": "v=√(GM/r)",
    "expr": "math.sqrt(x[\"gravitational_constant\"]*x[\"central_mass\"]/x[\"orbital_radius\"]))",
    "assumptions": []
  },
  {
    "id": "escape_speed",
    "domain": "astronomy",
    "name": "Escape speed",
    "target": "velocity",
    "inputs": [
      "gravitational_constant",
      "central_mass",
      "radius"
    ],
    "equation": "ve=√(2GM/r)",
    "expr": "math.sqrt(2*x[\"gravitational_constant\"]*x[\"central_mass\"]/x[\"radius\"]))",
    "assumptions": []
  },
  {
    "id": "kepler_period",
    "domain": "astronomy",
    "name": "Orbital period",
    "target": "period",
    "inputs": [
      "gravitational_constant",
      "central_mass",
      "orbital_radius"
    ],
    "equation": "T=2π√(r³/GM)",
    "expr": "2*math.pi*math.sqrt(x[\"orbital_radius\"]**3/(x[\"gravitational_constant\"]*x[\"central_mass\"])))",
    "assumptions": []
  },
  {
    "id": "hubble_distance",
    "domain": "astronomy",
    "name": "Hubble-law distance",
    "target": "distance",
    "inputs": [
      "recession_velocity",
      "hubble_constant"
    ],
    "equation": "d=v/H0",
    "expr": "x[\"recession_velocity\"]/x[\"hubble_constant\"])",
    "assumptions": []
  },
  {
    "id": "stress",
    "domain": "engineering",
    "name": "Stress",
    "target": "stress",
    "inputs": [
      "force",
      "area"
    ],
    "equation": "σ=F/A",
    "expr": "x[\"force\"]/x[\"area\"])",
    "assumptions": []
  },
  {
    "id": "strain",
    "domain": "engineering",
    "name": "Strain",
    "target": "strain",
    "inputs": [
      "length_change",
      "initial_length"
    ],
    "equation": "ε=ΔL/L0",
    "expr": "x[\"length_change\"]/x[\"initial_length\"])",
    "assumptions": []
  },
  {
    "id": "young_modulus",
    "domain": "engineering",
    "name": "Young modulus",
    "target": "young_modulus",
    "inputs": [
      "stress",
      "strain"
    ],
    "equation": "E=σ/ε",
    "expr": "x[\"stress\"]/x[\"strain\"])",
    "assumptions": []
  },
  {
    "id": "torque",
    "domain": "engineering",
    "name": "Torque",
    "target": "torque",
    "inputs": [
      "force",
      "lever_arm",
      "angle"
    ],
    "equation": "τ=rFsinθ",
    "expr": "x[\"lever_arm\"]*x[\"force\"]*math.sin(x[\"angle\"]*math.pi/180))",
    "assumptions": []
  },
  {
    "id": "hydraulic_force",
    "domain": "engineering",
    "name": "Hydraulic force",
    "target": "force2",
    "inputs": [
      "force1",
      "area1",
      "area2"
    ],
    "equation": "F2=F1A2/A1",
    "expr": "x[\"force1\"]*x[\"area2\"]/x[\"area1\"])",
    "assumptions": []
  },
  {
    "id": "coulomb_force",
    "domain": "electricity",
    "name": "Coulomb force",
    "target": "force",
    "inputs": [
      "charge1",
      "charge2",
      "distance",
      "coulomb_constant"
    ],
    "equation": "F=ke|q1q2|/r²",
    "expr": "x[\"coulomb_constant\"]*abs(x[\"charge1\"]*x[\"charge2\"])/x[\"distance\"]**2)",
    "assumptions": []
  },
  {
    "id": "electric_field",
    "domain": "electricity",
    "name": "Point charge electric field",
    "target": "electric_field",
    "inputs": [
      "charge",
      "distance",
      "coulomb_constant"
    ],
    "equation": "E=ke|q|/r²",
    "expr": "x[\"coulomb_constant\"]*abs(x[\"charge\"])/x[\"distance\"]**2)",
    "assumptions": []
  },
  {
    "id": "electric_potential",
    "domain": "electricity",
    "name": "Electric potential",
    "target": "electric_potential",
    "inputs": [
      "energy",
      "charge"
    ],
    "equation": "V=E/q",
    "expr": "x[\"energy\"]/x[\"charge\"])",
    "assumptions": []
  },
  {
    "id": "electric_work",
    "domain": "electricity",
    "name": "Electrical work",
    "target": "energy",
    "inputs": [
      "charge",
      "voltage"
    ],
    "equation": "W=qU",
    "expr": "x[\"charge\"]*x[\"voltage\"])",
    "assumptions": []
  },
  {
    "id": "capacitor_parallel_plate",
    "domain": "electricity",
    "name": "Parallel plate capacitance",
    "target": "capacitance",
    "inputs": [
      "permittivity",
      "area",
      "plate_distance"
    ],
    "equation": "C=εA/d",
    "expr": "x[\"permittivity\"]*x[\"area\"]/x[\"plate_distance\"])",
    "assumptions": []
  },
  {
    "id": "capacitor_series",
    "domain": "electricity",
    "name": "Series capacitors",
    "target": "capacitance",
    "inputs": [
      "capacitance1",
      "capacitance2"
    ],
    "equation": "Ceq=(C1C2)/(C1+C2)",
    "expr": "x[\"capacitance1\"]*x[\"capacitance2\"]/(x[\"capacitance1\"]+x[\"capacitance2\"]))",
    "assumptions": []
  },
  {
    "id": "capacitor_parallel",
    "domain": "electricity",
    "name": "Parallel capacitors",
    "target": "capacitance",
    "inputs": [
      "capacitance1",
      "capacitance2"
    ],
    "equation": "Ceq=C1+C2",
    "expr": "x[\"capacitance1\"]+x[\"capacitance2\"])",
    "assumptions": []
  },
  {
    "id": "resistors_series",
    "domain": "electricity",
    "name": "Series resistors",
    "target": "resistance",
    "inputs": [
      "resistance1",
      "resistance2"
    ],
    "equation": "Req=R1+R2",
    "expr": "x[\"resistance1\"]+x[\"resistance2\"])",
    "assumptions": []
  },
  {
    "id": "resistors_parallel",
    "domain": "electricity",
    "name": "Parallel resistors",
    "target": "resistance",
    "inputs": [
      "resistance1",
      "resistance2"
    ],
    "equation": "Req=R1R2/(R1+R2)",
    "expr": "x[\"resistance1\"]*x[\"resistance2\"]/(x[\"resistance1\"]+x[\"resistance2\"]))",
    "assumptions": []
  },
  {
    "id": "source_current",
    "domain": "electricity",
    "name": "Source current with internal resistance",
    "target": "current",
    "inputs": [
      "emf",
      "resistance",
      "internal_resistance"
    ],
    "equation": "I=ε/(R+r)",
    "expr": "x[\"emf\"]/(x[\"resistance\"]+x[\"internal_resistance\"]))",
    "assumptions": []
  },
  {
    "id": "terminal_voltage",
    "domain": "electricity",
    "name": "Source terminal voltage",
    "target": "voltage",
    "inputs": [
      "emf",
      "current",
      "internal_resistance"
    ],
    "equation": "U=ε-Ir",
    "expr": "x[\"emf\"]-x[\"current\"]*x[\"internal_resistance\"])",
    "assumptions": []
  },
  {
    "id": "magnetic_field_wire",
    "domain": "magnetism",
    "name": "Magnetic field of straight wire",
    "target": "magnetic_field",
    "inputs": [
      "permeability",
      "current",
      "distance"
    ],
    "equation": "B=μI/(2πr)",
    "expr": "x[\"permeability\"]*x[\"current\"]/(2*math.pi*x[\"distance\"]))",
    "assumptions": []
  },
  {
    "id": "solenoid_field",
    "domain": "magnetism",
    "name": "Solenoid magnetic field",
    "target": "magnetic_field",
    "inputs": [
      "permeability",
      "turns_per_length",
      "current"
    ],
    "equation": "B=μnI",
    "expr": "x[\"permeability\"]*x[\"turns_per_length\"]*x[\"current\"])",
    "assumptions": []
  },
  {
    "id": "lc_period",
    "domain": "electromagnetism",
    "name": "LC period",
    "target": "period",
    "inputs": [
      "inductance",
      "capacitance"
    ],
    "equation": "T=2π√LC",
    "expr": "2*math.pi*math.sqrt(x[\"inductance\"]*x[\"capacitance\"]))",
    "assumptions": []
  },
  {
    "id": "lc_frequency",
    "domain": "electromagnetism",
    "name": "LC frequency",
    "target": "frequency",
    "inputs": [
      "inductance",
      "capacitance"
    ],
    "equation": "f=1/(2π√LC)",
    "expr": "1/(2*math.pi*math.sqrt(x[\"inductance\"]*x[\"capacitance\"])))",
    "assumptions": []
  },
  {
    "id": "inductor_energy",
    "domain": "electromagnetism",
    "name": "Inductor energy",
    "target": "energy",
    "inputs": [
      "inductance",
      "current"
    ],
    "equation": "E=LI²/2",
    "expr": ".5*x[\"inductance\"]*x[\"current\"]**2)",
    "assumptions": []
  },
  {
    "id": "oscillation_max_velocity",
    "domain": "oscillations",
    "name": "SHM maximum speed",
    "target": "velocity",
    "inputs": [
      "amplitude",
      "angular_frequency"
    ],
    "equation": "vmax=Aω",
    "expr": "x[\"amplitude\"]*x[\"angular_frequency\"])",
    "assumptions": []
  },
  {
    "id": "oscillation_max_acceleration",
    "domain": "oscillations",
    "name": "SHM maximum acceleration",
    "target": "acceleration",
    "inputs": [
      "amplitude",
      "angular_frequency"
    ],
    "equation": "amax=Aω²",
    "expr": "x[\"amplitude\"]*x[\"angular_frequency\"]**2)",
    "assumptions": []
  },
  {
    "id": "oscillation_energy",
    "domain": "oscillations",
    "name": "SHM total energy",
    "target": "energy",
    "inputs": [
      "mass",
      "angular_frequency",
      "amplitude"
    ],
    "equation": "E=mω²A²/2",
    "expr": ".5*x[\"mass\"]*x[\"angular_frequency\"]**2*x[\"amplitude\"]**2)",
    "assumptions": []
  },
  {
    "id": "sound_intensity",
    "domain": "waves",
    "name": "Sound intensity",
    "target": "intensity",
    "inputs": [
      "power",
      "area"
    ],
    "equation": "I=P/A",
    "expr": "x[\"power\"]/x[\"area\"])",
    "assumptions": []
  },
  {
    "id": "spherical_sound_intensity",
    "domain": "waves",
    "name": "Spherical sound intensity",
    "target": "intensity",
    "inputs": [
      "power",
      "radius"
    ],
    "equation": "I=P/(4πr²)",
    "expr": "x[\"power\"]/(4*math.pi*x[\"radius\"]**2))",
    "assumptions": []
  },
  {
    "id": "open_pipe_fundamental",
    "domain": "waves",
    "name": "Open pipe fundamental",
    "target": "frequency",
    "inputs": [
      "sound_speed",
      "length"
    ],
    "equation": "f=v/(2L)",
    "expr": "x[\"sound_speed\"]/(2*x[\"length\"]))",
    "assumptions": []
  },
  {
    "id": "closed_pipe_fundamental",
    "domain": "waves",
    "name": "Closed pipe fundamental",
    "target": "frequency",
    "inputs": [
      "sound_speed",
      "length"
    ],
    "equation": "f=v/(4L)",
    "expr": "x[\"sound_speed\"]/(4*x[\"length\"]))",
    "assumptions": []
  },
  {
    "id": "diffraction_minimum",
    "domain": "optics",
    "name": "Single slit minimum",
    "target": "angle",
    "inputs": [
      "wavelength",
      "slit_width",
      "order"
    ],
    "equation": "sinθ=mλ/a",
    "expr": "math.asin(max(-1,min(1,x[\"order\"]*x[\"wavelength\"]/x[\"slit_width\"])))*180/math.pi)",
    "assumptions": []
  },
  {
    "id": "malus",
    "domain": "optics",
    "name": "Malus law",
    "target": "intensity",
    "inputs": [
      "initial_intensity",
      "angle"
    ],
    "equation": "I=I0cos²θ",
    "expr": "x[\"initial_intensity\"]*math.cos(x[\"angle\"]*math.pi/180)**2)",
    "assumptions": []
  },
  {
    "id": "photoelectric",
    "domain": "modern",
    "name": "Photoelectric kinetic energy",
    "target": "kinetic_energy",
    "inputs": [
      "planck_constant",
      "frequency",
      "work_function"
    ],
    "equation": "K=hf-φ",
    "expr": "x[\"planck_constant\"]*x[\"frequency\"]-x[\"work_function\"])",
    "assumptions": []
  },
  {
    "id": "stopping_voltage",
    "domain": "modern",
    "name": "Stopping voltage",
    "target": "voltage",
    "inputs": [
      "kinetic_energy",
      "elementary_charge"
    ],
    "equation": "Vs=K/e",
    "expr": "x[\"kinetic_energy\"]/x[\"elementary_charge\"])",
    "assumptions": []
  },
  {
    "id": "relativistic_energy",
    "domain": "relativity",
    "name": "Relativistic energy",
    "target": "energy",
    "inputs": [
      "lorentz_factor",
      "mass",
      "speed_of_light"
    ],
    "equation": "E=γmc²",
    "expr": "x[\"lorentz_factor\"]*x[\"mass\"]*x[\"speed_of_light\"]**2)",
    "assumptions": []
  },
  {
    "id": "time_dilation",
    "domain": "relativity",
    "name": "Time dilation",
    "target": "time",
    "inputs": [
      "lorentz_factor",
      "proper_time"
    ],
    "equation": "Δt=γΔt0",
    "expr": "x[\"lorentz_factor\"]*x[\"proper_time\"])",
    "assumptions": []
  },
  {
    "id": "length_contraction",
    "domain": "relativity",
    "name": "Length contraction",
    "target": "length",
    "inputs": [
      "proper_length",
      "lorentz_factor"
    ],
    "equation": "L=L0/γ",
    "expr": "x[\"proper_length\"]/x[\"lorentz_factor\"])",
    "assumptions": []
  },
  {
    "id": "nuclear_mass_defect",
    "domain": "nuclear",
    "name": "Nuclear mass defect",
    "target": "mass_defect",
    "inputs": [
      "proton_count",
      "neutron_count",
      "proton_mass",
      "neutron_mass",
      "nucleus_mass"
    ],
    "equation": "Δm=Zmp+Nmn-m",
    "expr": "x[\"proton_count\"]*x[\"proton_mass\"]+x[\"neutron_count\"]*x[\"neutron_mass\"]-x[\"nucleus_mass\"])",
    "assumptions": []
  },
  {
    "id": "hubble_velocity",
    "domain": "astronomy",
    "name": "Hubble recession velocity",
    "target": "velocity",
    "inputs": [
      "hubble_constant",
      "distance"
    ],
    "equation": "v=H0d",
    "expr": "x[\"hubble_constant\"]*x[\"distance\"])",
    "assumptions": []
  },
  {
    "id": "luminosity_flux",
    "domain": "astronomy",
    "name": "Flux from luminosity",
    "target": "flux",
    "inputs": [
      "luminosity",
      "distance"
    ],
    "equation": "F=L/(4πd²)",
    "expr": "x[\"luminosity\"]/(4*math.pi*x[\"distance\"]**2))",
    "assumptions": []
  },
  {
    "id": "bernoulli_velocity",
    "domain": "fluids",
    "name": "Bernoulli speed from pressure difference",
    "target": "velocity",
    "inputs": [
      "pressure_difference",
      "density"
    ],
    "equation": "v=√(2Δp/ρ)",
    "expr": "math.sqrt(2*x[\"pressure_difference\"]/x[\"density\"]))",
    "assumptions": []
  },
  {
    "id": "thermal_conduction",
    "domain": "thermal",
    "name": "Conductive heat transfer",
    "target": "heat",
    "inputs": [
      "thermal_conductivity",
      "area",
      "temperature_difference",
      "thickness",
      "time"
    ],
    "equation": "Q=kAΔTt/L",
    "expr": "x[\"thermal_conductivity\"]*x[\"area\"]*x[\"temperature_difference\"]*x[\"time\"]/x[\"thickness\"])",
    "assumptions": []
  },
  {
    "id": "stefan_boltzmann",
    "domain": "thermal",
    "name": "Thermal radiation power",
    "target": "power",
    "inputs": [
      "emissivity",
      "stefan_boltzmann_constant",
      "area",
      "temperature"
    ],
    "equation": "P=εσAT⁴",
    "expr": "x[\"emissivity\"]*x[\"stefan_boltzmann_constant\"]*x[\"area\"]*x[\"temperature\"]**4)",
    "assumptions": []
  },
  {
    "id": "carnot_efficiency",
    "domain": "thermal",
    "name": "Carnot efficiency",
    "target": "efficiency",
    "inputs": [
      "cold_temperature",
      "hot_temperature"
    ],
    "equation": "η=100(1-Tc/Th)",
    "expr": "100*(1-x[\"cold_temperature\"]/x[\"hot_temperature\"]))",
    "assumptions": []
  },
  {
    "id": "entropy",
    "domain": "thermal",
    "name": "Entropy change",
    "target": "entropy",
    "inputs": [
      "heat",
      "temperature"
    ],
    "equation": "ΔS=Q/T",
    "expr": "x[\"heat\"]/x[\"temperature\"])",
    "assumptions": []
  },
  {
    "id": "internal_energy_monatomic",
    "domain": "thermal",
    "name": "Monatomic ideal gas internal energy",
    "target": "energy",
    "inputs": [
      "moles",
      "gas_constant",
      "temperature"
    ],
    "equation": "U=3nRT/2",
    "expr": "1.5*x[\"moles\"]*x[\"gas_constant\"]*x[\"temperature\"])",
    "assumptions": []
  },
  {
    "id": "constant_gravity",
    "domain": "constants",
    "name": "Constant gravity",
    "target": "gravity",
    "inputs": [],
    "equation": "gravity=9.80665",
    "expr": "9.80665",
    "assumptions": []
  },
  {
    "id": "constant_speed_of_light",
    "domain": "constants",
    "name": "Constant speed_of_light",
    "target": "speed_of_light",
    "inputs": [],
    "equation": "speed_of_light=299792458",
    "expr": "299792458",
    "assumptions": []
  },
  {
    "id": "constant_gravitational_constant",
    "domain": "constants",
    "name": "Constant gravitational_constant",
    "target": "gravitational_constant",
    "inputs": [],
    "equation": "gravitational_constant=6.6743e-11",
    "expr": "6.6743e-11",
    "assumptions": []
  },
  {
    "id": "constant_planck_constant",
    "domain": "constants",
    "name": "Constant planck_constant",
    "target": "planck_constant",
    "inputs": [],
    "equation": "planck_constant=6.62607015e-34",
    "expr": "6.62607015e-34",
    "assumptions": []
  },
  {
    "id": "constant_boltzmann_constant",
    "domain": "constants",
    "name": "Constant boltzmann_constant",
    "target": "boltzmann_constant",
    "inputs": [],
    "equation": "boltzmann_constant=1.380649e-23",
    "expr": "1.380649e-23",
    "assumptions": []
  },
  {
    "id": "constant_elementary_charge",
    "domain": "constants",
    "name": "Constant elementary_charge",
    "target": "elementary_charge",
    "inputs": [],
    "equation": "elementary_charge=1.602176634e-19",
    "expr": "1.602176634e-19",
    "assumptions": []
  },
  {
    "id": "constant_gas_constant",
    "domain": "constants",
    "name": "Constant gas_constant",
    "target": "gas_constant",
    "inputs": [],
    "equation": "gas_constant=8.314462618",
    "expr": "8.314462618",
    "assumptions": []
  },
  {
    "id": "constant_avogadro_constant",
    "domain": "constants",
    "name": "Constant avogadro_constant",
    "target": "avogadro_constant",
    "inputs": [],
    "equation": "avogadro_constant=6.02214076e+23",
    "expr": "6.02214076e+23",
    "assumptions": []
  },
  {
    "id": "constant_coulomb_constant",
    "domain": "constants",
    "name": "Constant coulomb_constant",
    "target": "coulomb_constant",
    "inputs": [],
    "equation": "coulomb_constant=8987551792.3",
    "expr": "8987551792.3",
    "assumptions": []
  },
  {
    "id": "constant_stefan_boltzmann_constant",
    "domain": "constants",
    "name": "Constant stefan_boltzmann_constant",
    "target": "stefan_boltzmann_constant",
    "inputs": [],
    "equation": "stefan_boltzmann_constant=5.670374419e-8",
    "expr": "5.670374419e-8",
    "assumptions": []
  }
]
INPUT_UNITS = {
  "time": "s",
  "distance": "m",
  "height": "m",
  "radius": "m",
  "mass": "kg",
  "velocity": "m/s",
  "initial_velocity": "m/s",
  "final_velocity": "m/s",
  "acceleration": "m/s2",
  "gravity": "m/s2",
  "initial_speed": "m/s",
  "angle": "deg",
  "coefficient": "1",
  "normal_force": "N",
  "spring_constant": "N/m",
  "displacement": "m",
  "force": "N",
  "delta_momentum": "kg·m/s",
  "mass1": "kg",
  "mass2": "kg",
  "gravitational_constant": "m3/(kg·s2)",
  "central_mass": "kg",
  "work": "J",
  "useful_output": "J",
  "input": "J",
  "final_angular_velocity": "rad/s",
  "initial_angular_velocity": "rad/s",
  "angular_velocity": "rad/s",
  "volume": "m3",
  "area": "m2",
  "density": "kg/m3",
  "depth": "m",
  "fluid_density": "kg/m3",
  "displaced_volume": "m3",
  "area1": "m2",
  "velocity1": "m/s",
  "area2": "m2",
  "specific_heat": "J/(kg K)",
  "temperature_change": "K",
  "latent_heat": "J/kg",
  "celsius": "degC",
  "fahrenheit": "degF",
  "initial_length": "m",
  "expansion_coefficient": "1/K",
  "moles": "mol",
  "gas_constant": "J/(mol·K)",
  "temperature": "K",
  "pressure1": "Pa",
  "volume1": "m3",
  "volume2": "m3",
  "temperature1": "K",
  "temperature2": "K",
  "current": "A",
  "resistance": "ohm",
  "voltage": "V",
  "resistivity": "ohm·m",
  "length": "m",
  "power": "W",
  "charge": "C",
  "capacitance": "F",
  "magnetic_field": "T",
  "flux_change": "Wb",
  "period": "s",
  "frequency": "Hz",
  "wavelength": "m",
  "wave_speed": "m/s",
  "intensity": "W/m2",
  "reference_intensity": "W/m2",
  "sound_speed": "m/s",
  "observer_speed": "m/s",
  "source_speed": "m/s",
  "index1": "1",
  "angle1": "deg",
  "index2": "1",
  "focal_length": "m",
  "object_distance": "m",
  "image_distance": "m",
  "screen_distance": "m",
  "slit_separation": "m",
  "planck_constant": "J·s",
  "speed_of_light": "m/s",
  "momentum": "kg·m/s",
  "initial_quantity": "1",
  "half_life": "s",
  "decay_constant": "1/s",
  "number_of_nuclei": "1",
  "mass_defect": "kg",
  "orbital_radius": "m",
  "recession_velocity": "m/s",
  "hubble_constant": "1/s",
  "length_change": "m",
  "stress": "Pa",
  "strain": "1",
  "lever_arm": "m",
  "force1": "N",
  "charge1": "C",
  "charge2": "C",
  "coulomb_constant": "N·m2/C2",
  "energy": "J",
  "permittivity": "F/m",
  "plate_distance": "m",
  "capacitance1": "F",
  "capacitance2": "F",
  "resistance1": "ohm",
  "resistance2": "ohm",
  "emf": "V",
  "internal_resistance": "ohm",
  "permeability": "H/m",
  "turns_per_length": "1/m",
  "inductance": "H",
  "amplitude": "m",
  "angular_frequency": "rad/s",
  "slit_width": "m",
  "order": "1",
  "initial_intensity": "W/m2",
  "work_function": "J",
  "kinetic_energy": "J",
  "elementary_charge": "C",
  "lorentz_factor": "1",
  "proper_time": "s",
  "proper_length": "m",
  "proton_count": "1",
  "neutron_count": "1",
  "proton_mass": "kg",
  "neutron_mass": "kg",
  "nucleus_mass": "kg",
  "luminosity": "W",
  "pressure_difference": "Pa",
  "thermal_conductivity": "W/(m·K)",
  "temperature_difference": "K",
  "thickness": "m",
  "emissivity": "1",
  "cold_temperature": "K",
  "hot_temperature": "K",
  "heat": "J",
  "stefan_boltzmann_constant": "W/(m2·K4)"
}
OUTPUT_META = {
  "velocity": [
    "speed",
    "m/s"
  ],
  "distance": [
    "length",
    "m"
  ],
  "acceleration": [
    "acceleration",
    "m/s2"
  ],
  "time": [
    "time",
    "s"
  ],
  "range": [
    "length",
    "m"
  ],
  "height": [
    "length",
    "m"
  ],
  "force": [
    "force",
    "N"
  ],
  "momentum": [
    "momentum",
    "kg·m/s"
  ],
  "impulse": [
    "impulse",
    "N·s"
  ],
  "gravity": [
    "acceleration",
    "m/s2"
  ],
  "work": [
    "energy",
    "J"
  ],
  "energy": [
    "energy",
    "J"
  ],
  "power": [
    "power",
    "W"
  ],
  "efficiency": [
    "dimensionless",
    "%"
  ],
  "angular_velocity": [
    "angular_velocity",
    "rad/s"
  ],
  "angular_acceleration": [
    "angular_acceleration",
    "rad/s2"
  ],
  "density": [
    "density",
    "kg/m3"
  ],
  "pressure": [
    "pressure",
    "Pa"
  ],
  "flow_rate": [
    "flow_rate",
    "m3/s"
  ],
  "velocity2": [
    "speed",
    "m/s"
  ],
  "heat": [
    "energy",
    "J"
  ],
  "temperature": [
    "temperature",
    "K"
  ],
  "length_change": [
    "length",
    "m"
  ],
  "pressure2": [
    "pressure",
    "Pa"
  ],
  "volume2": [
    "volume",
    "m3"
  ],
  "charge": [
    "charge",
    "C"
  ],
  "voltage": [
    "voltage",
    "V"
  ],
  "current": [
    "current",
    "A"
  ],
  "resistance": [
    "resistance",
    "ohm"
  ],
  "capacitance": [
    "capacitance",
    "F"
  ],
  "magnetic_flux": [
    "magnetic_flux",
    "Wb"
  ],
  "emf": [
    "voltage",
    "V"
  ],
  "period": [
    "time",
    "s"
  ],
  "frequency": [
    "frequency",
    "Hz"
  ],
  "wavelength": [
    "length",
    "m"
  ],
  "sound_level": [
    "sound_level",
    "dB"
  ],
  "angle": [
    "angle",
    "deg"
  ],
  "image_distance": [
    "length",
    "m"
  ],
  "optical_power": [
    "optical_power",
    "D"
  ],
  "magnification": [
    "dimensionless",
    "1"
  ],
  "fringe_spacing": [
    "length",
    "m"
  ],
  "lorentz_factor": [
    "dimensionless",
    "1"
  ],
  "remaining_quantity": [
    "quantity",
    "1"
  ],
  "activity": [
    "activity",
    "Bq"
  ],
  "half_life": [
    "time",
    "s"
  ],
  "stress": [
    "pressure",
    "Pa"
  ],
  "strain": [
    "dimensionless",
    "1"
  ],
  "young_modulus": [
    "pressure",
    "Pa"
  ],
  "torque": [
    "torque",
    "N·m"
  ],
  "force2": [
    "force",
    "N"
  ],
  "electric_field": [
    "electric_field",
    "V/m"
  ],
  "electric_potential": [
    "voltage",
    "V"
  ],
  "magnetic_field": [
    "magnetic_field",
    "T"
  ],
  "intensity": [
    "intensity",
    "W/m2"
  ],
  "kinetic_energy": [
    "energy",
    "J"
  ],
  "length": [
    "length",
    "m"
  ],
  "mass_defect": [
    "mass",
    "kg"
  ],
  "flux": [
    "magnetic_flux",
    "Wb"
  ],
  "entropy": [
    "entropy",
    "J/K"
  ],
  "speed_of_light": [
    "speed",
    "m/s"
  ],
  "gravitational_constant": [
    "gravitational_constant",
    "m3/(kg·s2)"
  ],
  "planck_constant": [
    "action",
    "J·s"
  ],
  "boltzmann_constant": [
    "entropy",
    "J/K"
  ],
  "elementary_charge": [
    "charge",
    "C"
  ],
  "gas_constant": [
    "gas_constant",
    "J/(mol·K)"
  ],
  "avogadro_constant": [
    "quantity_per_mole",
    "1/mol"
  ],
  "coulomb_constant": [
    "coulomb_constant",
    "N·m2/C2"
  ],
  "stefan_boltzmann_constant": [
    "stefan_boltzmann_constant",
    "W/(m2·K4)"
  ]
}
SPECIAL_OUTPUT_META = {
  "celsius_kelvin": [
    "temperature",
    "K"
  ],
  "celsius_fahrenheit": [
    "temperature",
    "degF"
  ],
  "fahrenheit_celsius": [
    "temperature",
    "degC"
  ]
}
def _evaluate_formula(expression, values):
    expression = expression.strip()
    while expression.endswith(")") and expression.count("(") < expression.count(")"):
        expression = expression[:-1]
    tree = ast.parse(expression, mode="eval")

    def visit(node):
        if isinstance(node, ast.Expression):
            return visit(node.body)
        if isinstance(node, ast.Constant) and isinstance(node.value, (int, float)):
            return node.value
        if isinstance(node, ast.Subscript) and isinstance(node.value, ast.Name) and node.value.id == "x":
            key = node.slice.value if isinstance(node.slice, ast.Constant) else None
            if not isinstance(key, str) or key not in values:
                raise ValueError("Unknown formula input.")
            return values[key]
        if isinstance(node, ast.BinOp):
            left, right = visit(node.left), visit(node.right)
            ops = {ast.Add: lambda: left+right, ast.Sub: lambda: left-right, ast.Mult: lambda: left*right, ast.Div: lambda: left/right, ast.Pow: lambda: left**right}
            if type(node.op) not in ops:
                raise ValueError("Unsupported formula operator.")
            return ops[type(node.op)]()
        if isinstance(node, ast.UnaryOp):
            val = visit(node.operand)
            if isinstance(node.op, ast.UAdd): return val
            if isinstance(node.op, ast.USub): return -val
            raise ValueError("Unsupported unary formula operator.")
        if isinstance(node, ast.Attribute) and isinstance(node.value, ast.Name) and node.value.id == "math" and node.attr == "pi":
            return math.pi
        if isinstance(node, ast.Call) and not node.keywords:
            args = [visit(a) for a in node.args]
            funcs = {"abs":abs,"max":max,"min":min,"math.sqrt":math.sqrt,"math.sin":math.sin,"math.cos":math.cos,"math.asin":math.asin,"math.log":math.log,"math.log10":math.log10}
            name = node.func.id if isinstance(node.func, ast.Name) else (node.func.value.id+"."+node.func.attr if isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) else None)
            if name not in funcs:
                raise ValueError("Unsupported formula function.")
            return funcs[name](*args)
        raise ValueError("Unsupported formula syntax.")

    return visit(tree)

def _finite_number(value, name):
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise ValueError(f"{name} must be finite numeric.")
    return float(value)

def physics_calculate(args):
    if not isinstance(args, dict):
        raise ValueError("arguments must be an object.")
    if args.get("list_formulas"):
        formulas = []
        for formula in FORMULAS:
            metadata = SPECIAL_OUTPUT_META.get(formula["id"], OUTPUT_META.get(formula["target"], ["unknown", "SI"]))
            input_units = {key: INPUT_UNITS.get(key, "dimensionless") for key in formula["inputs"]}
            if "angle" in input_units:
                input_units["angle"] = "rad" if formula["id"] == "angular_velocity" else "deg"
            formulas.append({
                **{key: value for key, value in formula.items() if key != "expr"},
                "input_units": input_units,
                "output_dimension": metadata[0],
                "output_unit": metadata[1],
            })
        return {"success": True, "formula_count": len(FORMULAS), "formulas": formulas}
    formula_id = str(args.get("formula_id") or "").strip()
    target = str(args.get("target") or "").strip()
    values = args.get("values") or {}
    if not isinstance(values, dict):
        raise ValueError("values must be an object of numeric values already normalized to the formula's canonical input units.")
    constants = {"gravity":9.80665,"speed_of_light":299792458,"gravitational_constant":6.6743e-11,"planck_constant":6.62607015e-34,"boltzmann_constant":1.380649e-23,"elementary_charge":1.602176634e-19,"gas_constant":8.314462618,"avogadro_constant":6.02214076e+23,"coulomb_constant":8987551792.3,"stefan_boltzmann_constant":5.670374419e-8}
    values = {**constants, **values}
    if formula_id:
        selected = next((formula for formula in FORMULAS if formula["id"] == formula_id), None)
        if selected is None:
            raise ValueError("Unknown formula_id; call list_formulas=true to inspect the built-in catalog.")
    else:
        matches = [formula for formula in FORMULAS if (not target or target.casefold() in (formula["target"].casefold(), formula["name"].casefold())) and not [key for key in formula["inputs"] if key not in values]]
        if not matches:
            raise ValueError("No matching formula. Provide formula_id or target plus all required numerical inputs.")
        selected = matches[0]
    missing = [key for key in selected["inputs"] if key not in values]
    if missing:
        raise ValueError("Missing inputs: " + ", ".join(missing))
    x = {key: _finite_number(values[key], key) for key in selected["inputs"]}
    try:
        result = _evaluate_formula(selected["expr"], x)
    except Exception as exc:
        raise ValueError(f"Formula computation failed: {exc}") from None
    if isinstance(result, bool) or not isinstance(result, (int, float)) or not math.isfinite(float(result)):
        raise ValueError("Non-finite result. Check denominator and physical conditions.")
    metadata = SPECIAL_OUTPUT_META.get(selected["id"], OUTPUT_META.get(selected["target"], ["unknown", "SI"]))
    return {
        "success": True,
        "formula_id": selected["id"],
        "domain": selected["domain"],
        "formula_name": selected["name"],
        "target": selected["target"],
        "equation": selected["equation"],
        "inputs": x,
        "result": float(result),
        "to_unit": metadata[1],
        "dimension": metadata[0],
        "assumptions": selected.get("assumptions", []),
        "formula_count": len(FORMULAS),
    }
