package ingest

import (
	"strconv"
	"strings"
)

func valueAtPath(payload map[string]any, path string) (any, bool) {
	path = normalizePath(path)
	if path == "" {
		return nil, false
	}

	var current any = payload
	for _, segment := range strings.Split(path, ".") {
		switch node := current.(type) {
		case map[string]any:
			value, ok := node[segment]
			if !ok {
				return nil, false
			}
			current = value
		case []any:
			index, err := strconv.Atoi(segment)
			if err != nil || index < 0 || index >= len(node) {
				return nil, false
			}
			current = node[index]
		default:
			return nil, false
		}
	}

	return current, true
}

func setValueAtPath(payload map[string]any, path string, value any) {
	path = normalizePath(path)
	if path == "" {
		return
	}

	segments := strings.Split(path, ".")
	current := payload
	for index, segment := range segments {
		if index == len(segments)-1 {
			current[segment] = value
			return
		}

		next, ok := current[segment].(map[string]any)
		if !ok {
			next = map[string]any{}
			current[segment] = next
		}
		current = next
	}
}

func normalizePath(path string) string {
	path = strings.TrimSpace(path)
	path = strings.TrimPrefix(path, "$.")
	if path == "$" {
		return ""
	}
	return path
}
