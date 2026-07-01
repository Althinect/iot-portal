package ingest

import (
	"encoding/binary"
	"math"
	"strconv"
	"strings"
)

func evalJSONLogic(expression any, data map[string]any) any {
	switch typed := expression.(type) {
	case map[string]any:
		if len(typed) != 1 {
			return expression
		}
		for operator, values := range typed {
			return applyJSONLogicOperator(operator, values, data)
		}
	case []any:
		values := make([]any, 0, len(typed))
		for _, value := range typed {
			values = append(values, evalJSONLogic(value, data))
		}
		return values
	}
	return expression
}

func applyJSONLogicOperator(operator string, values any, data map[string]any) any {
	items := logicItems(values)
	switch operator {
	case "var":
		if len(items) == 0 {
			return nil
		}
		key, ok := items[0].(string)
		if !ok {
			return nil
		}
		value, _ := valueAtPath(data, key)
		return value
	case "+", "-", "*", "/", "min", "max":
		return numericJSONLogic(operator, items, data)
	case "==", "===", "!=", "!==", ">", ">=", "<", "<=":
		return compareJSONLogic(operator, items, data)
	case "and":
		for _, item := range items {
			value := evalJSONLogic(item, data)
			if !truthy(value) {
				return value
			}
		}
		if len(items) == 0 {
			return nil
		}
		return evalJSONLogic(items[len(items)-1], data)
	case "or":
		for _, item := range items {
			value := evalJSONLogic(item, data)
			if truthy(value) {
				return value
			}
		}
		return nil
	case "!":
		if len(items) == 0 {
			return true
		}
		return !truthy(evalJSONLogic(items[0], data))
	case "if":
		if len(items) < 2 {
			return nil
		}
		if truthy(evalJSONLogic(items[0], data)) {
			return evalJSONLogic(items[1], data)
		}
		if len(items) > 2 {
			return evalJSONLogic(items[2], data)
		}
		return nil
	case "reinterpret_big_endian_float", "decode_big_endian_float":
		if len(items) == 0 {
			return nil
		}
		return decodeBigEndianFloat(evalJSONLogic(items[0], data))
	case "decode_twos_complement", "twos_complement", "twosComplement":
		if len(items) == 0 {
			return nil
		}
		bits := 16
		if len(items) > 1 {
			bits = int(toFloat(evalJSONLogic(items[1], data)))
		}
		return decodeTwosComplement(evalJSONLogic(items[0], data), bits)
	default:
		return values
	}
}

func logicItems(values any) []any {
	if items, ok := values.([]any); ok {
		return items
	}
	return []any{values}
}

func numericJSONLogic(operator string, items []any, data map[string]any) any {
	numbers := make([]float64, 0, len(items))
	for _, item := range items {
		numbers = append(numbers, toFloat(evalJSONLogic(item, data)))
	}
	if len(numbers) == 0 {
		return nil
	}
	result := numbers[0]
	switch operator {
	case "+":
		result = 0
		for _, number := range numbers {
			result += number
		}
	case "-":
		for _, number := range numbers[1:] {
			result -= number
		}
	case "*":
		result = 1
		for _, number := range numbers {
			result *= number
		}
	case "/":
		for _, number := range numbers[1:] {
			if number != 0 {
				result /= number
			}
		}
	case "min":
		for _, number := range numbers[1:] {
			result = math.Min(result, number)
		}
	case "max":
		for _, number := range numbers[1:] {
			result = math.Max(result, number)
		}
	}
	return result
}

func compareJSONLogic(operator string, items []any, data map[string]any) bool {
	if len(items) < 2 {
		return false
	}
	left := evalJSONLogic(items[0], data)
	right := evalJSONLogic(items[1], data)

	switch operator {
	case "==", "===":
		return left == right
	case "!=", "!==":
		return left != right
	case ">":
		return toFloat(left) > toFloat(right)
	case ">=":
		return toFloat(left) >= toFloat(right)
	case "<":
		return toFloat(left) < toFloat(right)
	case "<=":
		return toFloat(left) <= toFloat(right)
	default:
		return false
	}
}

func truthy(value any) bool {
	switch typed := value.(type) {
	case nil:
		return false
	case bool:
		return typed
	case string:
		return typed != ""
	case float64:
		return typed != 0
	case int:
		return typed != 0
	default:
		return true
	}
}

func toFloat(value any) float64 {
	switch typed := value.(type) {
	case float64:
		return typed
	case float32:
		return float64(typed)
	case int:
		return float64(typed)
	case int64:
		return float64(typed)
	case jsonNumber:
		value, err := typed.Float64()
		if err != nil {
			return 0
		}
		return value
	case bool:
		if typed {
			return 1
		}
		return 0
	case string:
		value, err := strconv.ParseFloat(strings.TrimSpace(typed), 64)
		if err != nil {
			return 0
		}
		return value
	default:
		return 0
	}
}

type jsonNumber interface {
	Float64() (float64, error)
}

func decodeBigEndianFloat(value any) any {
	unsigned := uint32(toFloat(value))
	return float64(math.Float32frombits(binary.BigEndian.Uint32([]byte{
		byte(unsigned >> 24),
		byte(unsigned >> 16),
		byte(unsigned >> 8),
		byte(unsigned),
	})))
}

func decodeTwosComplement(value any, bits int) any {
	if bits <= 0 || bits > 63 {
		return nil
	}
	unsigned := int64(toFloat(value))
	signBit := int64(1) << (bits - 1)
	mask := int64(1) << bits
	if unsigned&signBit != 0 {
		return unsigned - mask
	}
	return unsigned
}
