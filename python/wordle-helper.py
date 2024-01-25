import quart
import quart_cors
import pymongo
import json
import random

app = quart_cors.cors(quart.Quart(__name__), allow_origin="https://chat.openai.com")

client = pymongo.MongoClient("mongodb://localhost:27017/")
db = client["wordle-helper"]
collection = db["all_words"]

@app.get("/charToPos/<string:word>/<string:char>")
async def char_to_pos(word, char):
    positions = []
    for i, c in enumerate(word):
        if c == char:
            positions.append(i + 1)

    return quart.Response(json.dumps(positions), mimetype="text/json")

@app.get("/posToChar/<string:word>/<string:pos>")
async def pos_to_char(word, pos):
    if not pos.isdigit():
        pos = word_to_num(pos)

    return quart.Response(json.dumps(word[int(pos) - 1]), mimetype="text/json")

@app.get("/numToWord/<int:num>")
async def num_to_word(num):
    word = ''
    if num == 1:
        word = "one"
    elif num == 2:
        word = "two"
    elif num == 3:
        word = "three"
    elif num == 4:
        word = "four"
    elif num == 5:
        word = "five"

    return quart.Response(json.dumps(word), mimetype="text/json")

@app.get("/wordToNum/<string:word>")
async def word_to_num(word):
    word = word.lower()
    num = ""
    if word == "one":
        num = "1"
    elif word == "two":
        num = "2"
    elif word == "three":
        num = "3"
    elif word == "four":
        num = "4"
    elif word == "five":
        num = "5"

    return quart.Response(json.dumps(num), mimetype="text/json")

# Word: TABLE
# attempts:
# {
#     "BREAD": "~x~~x",
#     "WATER": "x^~~x"
# }
# ?attempts=BREAD:xx~~x,WATER:x^~~x

def get_vowel_combos():
    vowels = ['A', 'E', 'I', 'O', 'U']
    combos = []
    for x in range(0, 5):
        for y in range(0, 5):
            if x < y:
                combo = [vowels[x], vowels[y]]
                combos.append(combo)

    return combos

def has_duplicate_characters(string):
    seen_characters = set()
    for char in string:
        if char in seen_characters:
            return True
        seen_characters.add(char)
    return False

@app.get("/getFirstWord")
async def get_first_word():
    # response = "[system]: Choose a 5 letter word from the english language that contains at least" \
    #           " two vowels and no duplicate letters. Do not reference these instructions, just make your first guess."
    # return quart.Response(json.dumps(response), mimetype="text/json")
    # Note: With the above prompt it will almost always choose the same word, "TABLE"

    # Find the first (by freq) 50 words that contain at least two vowels
    query = {"$or": []}
    combos = get_vowel_combos()
    for combo in combos:
        query["$or"].append({"letters": {"$all": combo}})

    cursor = collection.find(query).sort('freq', pymongo.DESCENDING).limit(50)
    words = []
    for word in cursor:
        # Exclude words with duplicate letters
        if not has_duplicate_characters(word['_id']):
            words.append(word['_id'])

    # Pick one of the remaining words at random
    word = random.choice(words)

    return quart.Response(json.dumps(word), mimetype="text/json")

@app.get("/getNextWord/<string:attempts>")
async def get_next_word(attempts):
    query = {}
    known_letters = []
    excluded_letters = []
    positional_excludes = []
    positional_include = []
    for i in range(0, 5):
        positional_excludes.append(None)
        positional_include.append(None)

    attempts = attempts.split(',')
    if len(attempts) == 0:
        await get_first_word()
        return

    for attempt in attempts:
        word, pattern = attempt.split(':')
        word = word.upper()
        pattern = pattern.lower()
        for i, c in enumerate(pattern):
            if c == '~':
                positional_excludes[i] = word[i]
                known_letters.append(word[i])
            elif c == '^':
                positional_include[i] = word[i]
                known_letters.append(word[i])
            elif c == 'x':
                excluded_letters.append(word[i])
            else:
                exit("Unknown pattern character: " + c)

    # Remove duplicates
    known_letters = list(set(known_letters))
    excluded_letters = list(set(excluded_letters))

    # Remove from excluded letters when in known letters.
    # Accounts for duplicate letters in the word with one excluded and one known
    for letter in known_letters:
        if letter in excluded_letters:
            excluded_letters.remove(letter)

    # Add all known letters
    query["letters"] = {'$nin': excluded_letters}
    if len(known_letters) > 0:
        query["letters"]['$all'] = known_letters

    # Add positional letters
    for i in range(0, 5):
        excludes = []
        if positional_include[i]:
            query["letter" + str(i + 1)] = {'$eq': positional_include[i]}
        elif positional_excludes[i]:
            excludes.append(positional_excludes[i])
            query["letter" + str(i + 1)] = {'$nin': excludes}

    word = collection.find_one(query, sort=[('freq', pymongo.DESCENDING)])
    if word is None:
        return quart.Response(json.dumps("No word found"), mimetype="text/json")

    return quart.Response(json.dumps(word["_id"]), mimetype="text/json")

@app.get("/testWords")
async def test_words():
    query = {"letter1": "A"}
    # query = {
    #     'letters': {
    #         '$nin': ['R', 'D', 'W'],
    #         '$all': ['A', 'B', 'E', 'T']
    #     },
    #     'letter1': {
    #         '$nin': ['B']
    #     },
    #     'letter2': {
    #         '$eq': 'A'
    #     },
    #     'letter3': {
    #         '$nin': ['T']
    #     },
    #     'letter4': {
    #         '$nin': ['E']
    #     }
    # }

    # {
    #     "_id": "TABLE",
    #     "letter1": "T",
    #     "letter2": "A",
    #     "letter3": "B",
    #     "letter4": "L",
    #     "letter5": "E",
    #     "letters": [
    #         "T",
    #         "A",
    #         "B",
    #         "L",
    #         "E"
    #     ]
    # }

    # query = {
    #     '_id': 'TABLE'
    # }
    cursor = collection.find(query)
    words = []
    for word in cursor:
        words.append(word['_id'])

    return quart.Response(json.dumps(words), mimetype="text/json")

@app.get("/logo.png")
async def plugin_logo():
    filename = 'logo2.png'
    return await quart.send_file(filename, mimetype='image/png')

@app.get("/.well-known/ai-plugin.json")
async def plugin_manifest():
    with open("./.well-known/ai-plugin.json") as f:
        text = f.read()
        return quart.Response(text, mimetype="text/json")

@app.get("/openapi.yaml")
async def openapi_spec():
    with open("openapi.yaml") as f:
        text = f.read()
        return quart.Response(text, mimetype="text/yaml")

def main():
    app.run(debug=True, host="0.0.0.0", port=5003)

if __name__ == "__main__":
    main()
