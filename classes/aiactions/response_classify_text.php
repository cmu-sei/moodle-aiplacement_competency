<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace aiplacement_classifyassist\aiactions;
use core_ai\aiactions\responses\response_base;

class response_classify_text extends response_base {
    private ?string $id = null;

    private ?string $fingerprint = null;

    private ?string $generatedcontent = null;

    private ?string $finishreason = null;

    private ?string $prompttokens = null;

    private ?string $completiontokens = null;

    private ?string $response = null;

    private array $labels = [];

    public function __construct(
        bool $success,
        int $errorcode = 0,
        string $errormessage = '',
    ) {
        parent::__construct(
            success: $success,
            actionname: 'classify_text',
            errorcode: $errorcode,
            errormessage: $errormessage,
        );
    }

    #[\Override]
    public function set_response_data(array $response): void {
        $this->id = $response['id'] ?? null;
        $this->fingerprint = $response['fingerprint'] ?? null;
        $this->response = $response['response'] ?? null;
        $this->finishreason = $response['finishreason'] ?? null;
        $this->prompttokens = $response['prompttokens'] ?? null;
        $this->completiontokens = $response['completiontokens'] ?? null;
        $this->model = $response['model'] ?? null;
    }

    #[\Override]
    public function get_response_data(): array {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'response' => $this->response,
            'finishreason' => $this->finishreason,
            'prompttokens' => $this->prompttokens,
            'completiontokens' => $this->completiontokens,
            'model' => $this->model,
        ];
    }

    public function is_error(): bool {
        return !$this->get_success();
    }

    public function get_error(): string {
        return $this->get_errormessage() ?? 'Unknown error';
    }

    public function set_labels(array $labels): void {
        $this->labels = $labels;
    }

    public function get_labels(): array {
        return $this->labels;
    }
}