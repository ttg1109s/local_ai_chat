"""Unit conversion tool for compatible physical and everyday units."""
from .common import *
import math

# Each unit maps to a dimension and a scale to its base unit.
# Temperature uses affine conversion via (value + offset) * scale.
UNITS = {
    "time": {"s": (1,0), "sec": (1,0), "second": (1,0), "seconds": (1,0), "ms": (1e-3,0), "millisecond": (1e-3,0), "us": (1e-6,0), "µs": (1e-6,0), "ns": (1e-9,0), "min": (60,0), "minute": (60,0), "minutes": (60,0), "h": (3600,0), "hr": (3600,0), "hour": (3600,0), "day": (86400,0), "week": (604800,0),
    "length": {"m": (1,0), "meter": (1,0), "metre": (1,0), "km": (1000,0), "kilometer": (1000,0), "kilometre": (1000,0), "cm": (.01,0), "mm": (.001,0), "um": (1e-6,0), "µm": (1e-6,0), "nm": (1e-9,0), "ft": (.3048,0), "foot": (.3048,0), "feet": (.3048,0), "in": (.0254,0), "inch": (.0254,0), "mi": (1609.344,0), "mile": (1609.344,0), "yd": (.9144,0), "yard": (.9144,0),
    "mass": {"kg": (1,0), "kilogram": (1,0), "g": (.001,0), "gram": (.001,0), "mg": (1e-6,0), "tonne": (1000,0), "t": (1000,0), "lb": (.45359237,0), "lbs": (.45359237,0), "pound": (.45359237,0), "oz": (.028349523125,0), "ounce": (.028349523125,0),
    "speed": {"m/s": (1,0), "mps": (1,0), "km/h": (1/3.6,0), "kph": (1/3.6,0), "mph": (.44704,0), "ft/s": (.3048,0), "kn": (.514444444444,0), "knot": (.514444444444,0),
    "acceleration": {"m/s2": (1,0), "m/s^2": (1,0), "g": (9.80665,0), "ft/s2": (.3048,0),
    "force": {"N": (1,0), "newton": (1,0), "kN": (1000,0), "MN": (1e6,0), "lbf": (4.4482216152605,0), "kgf": (9.80665,0),
    "pressure": {"Pa": (1,0), "kPa": (1000,0), "MPa": (1e6,0), "bar": (100000,0), "mbar": (100,0), "atm": (101325,0), "psi": (6894.757293168,0), "mmHg": (133.322387415,0), "torr": (133.322368421,0),
    "energy": {"J": (1,0), "joule": (1,0), "kJ": (1000,0), "MJ": (1e6,0), "cal": (4.184,0), "kcal": (4184,0), "Wh": (3600,0), "kWh": (3600000,0), "eV": (1.602176634e-19,0),
    "power": {"W": (1,0), "watt": (1,0), "kW": (1000,0), "MW": (1e6,0), "hp": (745.699872,0),
    "volume": {"m3": (1,0), "m^3": (1,0), "L": (.001,0), "liter": (.001,0), "litre": (.001,0), "mL": (1e-6,0), "ml": (1e-6,0), "cm3": (1e-6,0), "cm^3": (1e-6,0), "ft3": (.028316846592,0), "ft^3": (.028316846592,0), "gal": (.003785411784,0), "US gallon": (.003785411784,0),
    "area": {"m2": (1,0), "m^2": (1,0), "cm2": (1e-4,0), "cm^2": (1e-4,0), "mm2": (1e-6,0), "mm^2": (1e-6,0), "ft2": (.09290304,0), "ft^2": (.09290304,0), "acre": (4046.8564224,0),
    "frequency": {"Hz": (1,0), "kHz": (1000,0), "MHz": (1e6,0), "GHz": (1e9,0),
    "angle": {"deg": (math.pi/180,0), "degree": (math.pi/180,0), "degrees": (math.pi/180,0), "rad": (1,0), "radian": (1,0), "radians": (1,0), "turn": (2*math.pi,0), "revolution": (2*math.pi,0),
    "temperature": {"K": (1,0), "kelvin": (1,0), "C": (1,273.15), "°C": (1,273.15), "celsius": (1,273.15), "F": (5/9,459.67), "°F": (5/9,459.67), "fahrenheit": (5/9,459.67),
    "voltage": {"V": (1,0), "mV": (1e-3,0), "kV": (1000,0),
    "current": {"A": (1,0), "mA": (1e-3,0), "uA": (1e-6,0), "µA": (1e-6,0), "kA": (1000,0),
    "resistance": {"ohm": (1,0), "Ω": (1,0), "kohm": (1000,0), "kΩ": (1000,0), "Mohm": (1e6,0), "MΩ": (1e6,0),
    "charge": {"C": (1,0), "mC": (1e-3,0), "uC": (1e-6,0), "µC": (1e-6,0), "nC": (1e-9,0),
    "density": {"kg/m3": (1,0), "kg/m^3": (1,0), "g/cm3": (1000,0), "g/cm^3": (1000,0),
    "wavelength": {"m": (1,0), "nm": (1e-9,0), "um": (1e-6,0), "µm": (1e-6,0), "mm": (1e-3,0), "cm": (1e-2,0),
    "angular_velocity": {"rad/s": (1,0), "rpm": (2*math.pi/60,0),
    "magnetic_field": {"T": (1,0), "mT": (1e-3,0), "uT": (1e-6,0), "µT": (1e-6,0),
    "specific_heat": {"J/(kg K)": (1,0), "J/kgK": (1,0), "kJ/(kg K)": (1000,0),
    "spring_constant": {"N/m": (1,0), "N/cm": (100,0),
    "mass_defect": {"kg": (1,0), "u": (1.66053906660e-27,0),
}

def convert_unit_tool(args):
    value = args.get("value")
    if isinstance(value, bool) or not isinstance(value, (int,float)) or not math.isfinite(float(value)):
        raise ValueError("value must be a finite number.")
    from_unit = str(args.get("from_unit", "")).strip()
    to_unit = str(args.get("to_unit", "")).strip()
    if not from_unit or not to_unit:
        raise ValueError("from_unit and to_unit are required.")
    requested_dimension = str(args.get("dimension", "")).strip().lower()
    if requested_dimension and requested_dimension not in UNITS:
        raise ValueError("Unknown dimension. Use one of: " + ", ".join(sorted(UNITS)))
    dimensions = [requested_dimension] if requested_dimension else list(UNITS)
    matches_from = [(dimension, UNITS[dimension][from_unit]) for dimension in dimensions if from_unit in UNITS[dimension]]
    matches_to = [(dimension, UNITS[dimension][to_unit]) for dimension in dimensions if to_unit in UNITS[dimension]]
    if len(matches_from) != 1 or len(matches_to) != 1:
        raise ValueError(f"Unsupported or ambiguous unit: {from_unit if len(matches_from)!=1 else to_unit}")
    dim_from, (scale_from, offset_from) = matches_from[0]
    dim_to, (scale_to, offset_to) = matches_to[0]
    if dim_from != dim_to:
        raise ValueError(f"Incompatible dimensions: {from_unit} ({dim_from}) cannot convert to {to_unit} ({dim_to}).")
    base = (float(value) + offset_from) * scale_from
    result = base / scale_to - offset_to
    if not math.isfinite(result):
        raise ValueError("Conversion result is not finite.")
    return {"value": float(value), "from_unit": from_unit, "to_unit": to_unit, "result": result, "dimension": dim_from}
