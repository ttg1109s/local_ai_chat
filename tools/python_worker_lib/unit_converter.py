"""Unit conversion tool for compatible physical and everyday units."""
from .common import *
import math

# Each unit maps to a dimension and a scale to its base unit.
# Temperature uses affine conversion via (value + offset) * scale.
UNITS = {
    "time": {"s": (1,0), "ms": (1e-3,0), "us": (1e-6,0), "ns": (1e-9,0), "min": (60,0), "h": (3600,0), "day": (86400,0), "week": (604800,0)},
    "length": {"m": (1,0), "km": (1000,0), "cm": (.01,0), "mm": (.001,0), "um": (1e-6,0), "nm": (1e-9,0), "ft": (.3048,0), "in": (.0254,0), "mi": (1609.344,0), "yd": (.9144,0)},
    "mass": {"kg": (1,0), "g": (.001,0), "mg": (1e-6,0), "t": (1000,0), "lb": (.45359237,0), "oz": (.028349523125,0), "u": (1.66053906660e-27,0)},
    "speed": {"m/s": (1,0), "km/h": (1/3.6,0), "mph": (.44704,0), "ft/s": (.3048,0), "kn": (.514444444444,0)},
    "acceleration": {"m/s2": (1,0), "g": (9.80665,0), "ft/s2": (.3048,0)},
    "force": {"N": (1,0), "kN": (1000,0), "MN": (1e6,0), "lbf": (4.4482216152605,0), "kgf": (9.80665,0)},
    "pressure": {"Pa": (1,0), "kPa": (1000,0), "MPa": (1e6,0), "bar": (100000,0), "mbar": (100,0), "atm": (101325,0), "psi": (6894.757293168,0), "mmHg": (133.322387415,0), "torr": (133.322368421,0)},
    "energy": {"J": (1,0), "kJ": (1000,0), "MJ": (1e6,0), "cal": (4.184,0), "kcal": (4184,0), "Wh": (3600,0), "kWh": (3600000,0), "eV": (1.602176634e-19,0)},
    "power": {"W": (1,0), "kW": (1000,0), "MW": (1e6,0), "hp": (745.699872,0)},
    "volume": {"m3": (1,0), "L": (.001,0), "mL": (1e-6,0), "cm3": (1e-6,0), "ft3": (.028316846592,0), "gal": (.003785411784,0)},
    "area": {"m2": (1,0), "cm2": (1e-4,0), "mm2": (1e-6,0), "ft2": (.09290304,0), "acre": (4046.8564224,0)},
    "frequency": {"Hz": (1,0), "kHz": (1000,0), "MHz": (1e6,0), "GHz": (1e9,0)},
    "angle": {"deg": (math.pi/180,0), "rad": (1,0), "turn": (2*math.pi,0)},
    "temperature": {"K": (1,0), "degC": (1,273.15), "degF": (5/9,459.67)},
    "voltage": {"V": (1,0), "mV": (1e-3,0), "kV": (1000,0)},
    "current": {"A": (1,0), "mA": (1e-3,0), "uA": (1e-6,0), "kA": (1000,0)},
    "resistance": {"ohm": (1,0), "kohm": (1000,0), "Mohm": (1e6,0)},
    "charge": {"C": (1,0), "mC": (1e-3,0), "uC": (1e-6,0), "nC": (1e-9,0)},
    "density": {"kg/m3": (1,0), "g/cm3": (1000,0)},
    "angular_velocity": {"rad/s": (1,0), "rpm": (2*math.pi/60,0)},
    "magnetic_field": {"T": (1,0), "mT": (1e-3,0), "uT": (1e-6,0)},
    "specific_heat": {"J/(kg K)": (1,0), "kJ/(kg K)": (1000,0)},
    "spring_constant": {"N/m": (1,0), "N/cm": (100,0)},
    "mass_defect": {"kg": (1,0), "u": (1.66053906660e-27,0)},
}

def convert_unit_tool(args):
    value = args.get("value")
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise ValueError("value must be a finite number.")

    dimension = args.get("dimension")
    if not isinstance(dimension, str) or dimension not in UNITS:
        raise ValueError("dimension is required and must be a canonical dimension.")

    from_unit = args.get("from_unit")
    to_unit = args.get("to_unit")
    if not isinstance(from_unit, str) or from_unit not in UNITS[dimension]:
        raise ValueError(f"Unsupported canonical from_unit for dimension {dimension}: {from_unit!r}")
    if not isinstance(to_unit, str) or to_unit not in UNITS[dimension]:
        raise ValueError(f"Unsupported canonical to_unit for dimension {dimension}: {to_unit!r}")

    scale_from, offset_from = UNITS[dimension][from_unit]
    scale_to, offset_to = UNITS[dimension][to_unit]
    base = (float(value) + offset_from) * scale_from
    result = base / scale_to - offset_to
    if not math.isfinite(result):
        raise ValueError("Conversion result is not finite.")
    return {"value": float(value), "from_unit": from_unit, "to_unit": to_unit, "result": result, "dimension": dimension}
